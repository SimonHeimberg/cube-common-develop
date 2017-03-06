#!/bin/bash
#
# to enable this hook, run
#  cp -bi src/CodeStyle/check-commit-cube.sh .git/hooks/pre-commit
#
# Does various checks on the files to be checked in


# handle args
cachedDiff=--cached
against=
if [ "$1" == '--changed' ]
then
    cachedDiff=
elif [[ "$1" == --* ]]
then
    echo 'basic checks on stashed (or committed) changes'
    echo
    echo usage: $0 '[--changed|REV]'
    exit
elif [ -n "$1" ]
then
    against=$(git rev-parse --verify "$1")
    [ -z $against ] && exit 64
    echo checking against revision $against
fi

if [ -n "$against" ]
    then true
elif git rev-parse --verify HEAD >/dev/null 2>&1
then
    against=HEAD
else
    # Initial commit: diff against an empty tree object
    against=4b825dc642cb6eb9a060e54bf8d69288fbee4904
fi

gitListFiles="git diff-index $cachedDiff --name-only --diff-filter ACMTUB -z $against"
xArgs0='xargs -0 -r'
xArgs0n1="$xArgs0 -n 1 -P $(nproc)"

retVal=0

showWarning() {
    if [ -n "$REPORTONLY" ]
    then
        retVal=1
        echo '--------- continueing check ---------'
        return
    fi
    echo -n '  continue anyway with c, abort with a: '
    while true
    do
        read -n 1 AW < /dev/tty
        case $AW in
        c)
            echo
            return
        ;;
        a|q)
            echo '  Abort'
            exit 2
        ;;
        esac
        read -t 1 FLUSH || true < /dev/tty # flush input
        echo -n ' [ca]? '
    done
}

checkScriptChanged() {
    #check if script has changed
    local pathInRepo
    pathInRepo=src/CodeStyle/check-commit-cube.sh

    if [ -n "$ccOrigPath" ]
        then true # is set from calling script
    elif [ -f vendor/cubetools/cube-common-develop/$pathInRepo ]
        then ccOrigPath=vendor/cubetools/cube-common-develop/$pathInRepo
    elif [ -f $pathInRepo ]
        then ccOrigPath=$pathInRepo
    else
        echo can not check if script is current, set ccOrigPath in your main check commit script
        showWarning
        return $?
    fi
    [ -z "$ccScriptPath" ] && ccScriptPath=$BASH_SOURCE # set to this scripts path if not set
    if [ "$ccOrigPath" -ot "$ccScriptPath" ]
        then true # current one is not older
    elif $gitListFiles --quiet
        then true # no files to check
    elif ! diff -q "$ccOrigPath" "$ccScriptPath" > /dev/null
    then
        # different content
        echo "update the pre-commit script by running cp -b $ccOrigPath '$ccScriptPath'"
        showWarning
    else # same content but older
        touch -r "$ccOrigPath" "$ccScriptPath" #update timestamp
    fi
}
checkScriptChanged

# Redirect output to stderr.
exec 1>&2

# Note that the use of brackets around a tr range is ok here, (it's
# even required, for portability to Solaris 10's /usr/bin/tr), since
# the square bracket bytes happen to fall in the designated range.
if test $(git diff $cachedDiff --name-only --diff-filter=A -z "$against" |
      LC_ALL=C tr -d '[ -~]\0' | wc -c) != 0
then
    cat <<\EOF
Error: Attempt to add a non-ASCII file name.

This can cause problems if you want to work with people on other platforms.

To be portable it is advisable to rename the file.
EOF
    showWarning
fi

set -e

# If there are whitespace errors, print the offending file names and warn.
git diff --check $cachedDiff "$against" -- || showWarning

# check for files with exec bit new set
if git diff $cachedDiff "$against" --raw -- | grep ':...[^7].. ...7..'
then
        echo 'above files with EXEC bit set now, is this expected?'
        echo 'if not, run $ chmod a-x $(git diff --cached --name-only)'
        showWarning
fi

avoidMsg='= avoid introducing what is colored above ='
avoidColors='ms=01;33'
# warn on unwanted terms
invPatts="\(array\).*json_decode|new .*Filesystem\(\)|->add\([^,]*, *['\"][^ ,:]*|->add\([^,]*, new |createForm\( *new  | dump\("
if git diff $cachedDiff "$against" -G "$invPatts" --color -- '*.php' | grep -v -E '^[^-+ ]*-.*('"$invPatts)" |
    GREP_COLORS="$avoidColors" grep --color=always -C 16 -E "$invPatts"
then
    echo "$avoidMsg" | GREP_COLORS="$avoidColors" grep --color=always colored
    cat <<'TO_HERE'
use this:
  * json_decode(xxx, true)           instead of (array) json_decode(xxx)
  * $container->get('filesystem')    instead of new Filesystem
  * ->add('name', TextType::class    instead of ->add('add', 'text' when creating forms (and ChoiceType, DateType, ...)
  * SomeType::class                  instead of new SomeType() in ->add( and ->createForm('
  * remove debugging                 dump(...) breaks non-debug run
TO_HERE
    showWarning
fi
invPatts="</input>|replace\(.*n.*<br.*\)|\{% *dump |\{\{[^}]dump\("
if git diff $cachedDiff "$against" -G "$invPatts" --color -- '*.htm*' | grep -v -E '^[^-+ ]*-.*('"$invPatts)" |
    GREP_COLORS="$avoidColors" grep --color=always -C 16 -E "$invPatts"
then
    echo "$avoidMsg" | GREP_COLORS="$avoidColors" grep --color=always colored
    cat <<'TO_HERE'
use this:
  * <input .../>            instead of <input ...></input> because input is standalone. Attr value="xx" is for values.
  * |nl2br                  instead of |replace({'\n', '<br>'}) (nl2br does NOT need {% autoescape false %} or |raw )
  * remove debugging        {% dump ... %} and {{ dump( ... ) do not work on non-debug run
TO_HERE
    showWarning
fi

# check files to commit for local changes
if [ -n "$cachedDiff" ] && ! $gitListFiles | $xArgs0 git diff-files --name-only --exit-code --
then
    echo 'above files for commit also modified in working directory'
    echo 'this may produce wrong results'
    showWarning
    ## or stash changes and unstash at end, see http://daurnimator.com/post/134519891749/testing-pre-commit-with-git
fi

#valid php ?
$gitListFiles -- '*.php' | $xArgs0n1 -- php -l

vendorBin=vendor/bin
[ -f $vendorBin/phpcs ] || vendorBin=bin

phpUnit='phpunit'

#check translation
$gitListFiles --quiet  -- '*.xliff' '*.xlf' || $phpUnit src/AppBundle/Tests/Resources/TranslationFileTest.php


syConsole=bin/console
[ -f $syConsole ] || syConsole=app/console

#check database (when an annotation or a variable changed in an entity)
$gitListFiles --quiet -G ' @|(protected|public|private) +\$\w' -- 'src/AppBundle/Entity/' || $syConsole doctrine:schema:validate || showWarning

#check twig
$gitListFiles -- '*.twig' | $xArgs0 $syConsole lint:twig --

#check yaml
$gitListFiles -- '*.yml' | $xArgs0n1 $syConsole lint:yaml --

#check composer
if ! $gitListFiles --quiet -- 'composer.*'
then
    if [ -f ./composer.phar ]
    then
        composerCmd='php ./composer.phar'
    elif [ -f ../composer.phar ]
    then
        composerCmd='php ../composer.phar'
    else
        composerCmd='composer'
    fi
    $composerCmd validate || showWarning
fi

#check style
phpCs="$vendorBin/phpcs --colors --report-width=auto -l -p"
$gitListFiles -- '*.php' '*.js' '*.css' | $xArgs0 -- $phpCs || showWarning
# config is in project dir

if [ "0" = "$retStat" ]
then
    echo failed
    exit $retStat
fi
