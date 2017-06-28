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
    local AW FLUSH
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
warnWhenMissing () {
    local lastRet=$?
    if [ 127 -eq $lastRet ] # not found error
    then
        showWarning
        return $?
    fi
    return $lastRet
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

# warn on unwanted terms

findUnwantedTerms () {
    # args: file-pattern, invalid-pattern
    local avoidMsg avoidColors filePatt invPatts r
    avoidMsg='= avoid introducing what is colored above ='
    avoidColors='ms=01;33'
    filePatt="$1"
    invPatts="$2"

    git diff $cachedDiff "$against" -G "$invPatts" --color -- "$filePatt" | grep -v -E '^[^-+ ]*-.*('"$invPatts)" |
        GREP_COLORS="$avoidColors" grep --color=always -C 16 -E "$invPatts"
    r=$?
    if [ 0 -eq $r ]
    then
        echo "$avoidMsg" | GREP_COLORS="$avoidColors" grep --color=always colored
    fi
    return $r
}
invPatts="\(array\).*json_decode|new .*Filesystem\(\)|->add\([^,]*, *['\"][^ ,:]*|->add\([^,]*, new |createForm\( *new  | dump\("
if findUnwantedTerms '*.php' "$invPatts"
then
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
invPatts="</input>|</br>|replace\(.*n.*<br.*\)|\{% *dump |\{\{[^}]dump\("
if findUnwantedTerms '*.htm*' "$invPatts"
then
    cat <<'TO_HERE'
use this:
  * <input .../>            instead of <input ...></input> because input is standalone. Attr value="xx" is for values.
  * <div>...</div> or <br>  instead of </br>, an unexisting tag
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
[ -d $vendorBin ] || vendorBin=bin

runPhpUnit () {
    if [ -z "$phpUnit" ]
    then
        if [ -f $vendorBin/phpunit ]
            then phpUnit=$vendorBin/phpunit
        else
            phpUnit='phpunit'
        fi
    fi

    $phpUnit "$@"
}

checkTranslations () {
    local transTest
    transTest=src/AppBundle/Tests/Resources/TranslationFileTest.php
    if [ ! -f $transTest ]
    then
        echo can not check translations, test $transTest is missing.
        showWarning
        return $?
    fi

    runPhpUnit $transTest || warnWhenMissing
}

#check translation
$gitListFiles --quiet  -- '*.xliff' '*.xlf' || checkTranslations

findSyConsole () {
    if [ -z "$syConsole" ]
    then
        syConsole=bin/console
        if [ -f $syConsole ]
            then true # OK
        elif [ -f app/console ]
            then syConsole=app/console
        else
            syConsoleError='console not available to run '$syConsole
        fi
    fi

    if [ -n "$syConsoleError" ]
        then return 127
    fi
}

syConsoleRun() {
    if ! findSyConsole
    then
        echo $syConsoleError $@
        return 127 # not found error
    fi
    $syConsole $@
}
syConsoleXargs () {
    local oneChar
    if ! findSyConsole  && read -N 1 oneChar
    then # no console but input, trigger error
        { printf "$oneChar"; cat; } | $xArgs0 -- echo $syConsoleError $@
        return 127
    fi
    $xArgs0 -- $syConsole $@
}
syConsoleXargsN1 () {
    local oneChar
    if ! findSyConsole && read -N 1 oneChar
    then # no console but input, trigger error listing files in one command
        { printf "$oneChar"; cat; } | $xArgs0 -- echo $syConsoleError "$@" for
        return 127
    fi
    $xArgs0n1 -- $syConsole "$@"
}


#check database (when an annotation or a variable changed in an entity)
$gitListFiles --quiet -G ' @|(protected|public|private) +\$\w' -- 'src/*/Entity/' || syConsoleRun doctrine:schema:validate || showWarning

#check twig
$gitListFiles -- '*.twig' | syConsoleXargs lint:twig || warnWhenMissing

#check yaml
$gitListFiles -- '*.yml' | syConsoleXargsN1 lint:yaml -- || warnWhenMissing

#check composer
if ! $gitListFiles --quiet -- 'composer.*'
then
    composerCmd=''
    for checkDir in . .. ../..
    do
        if [ -f $checkDir/composer.phar ]
        then
           composerCmd="php $checkDir/composer.phar"
           break
        fi
    done
    if [ -n "$composerCmd" ]
    then
        true # is set
    elif [ -n "$(type -t composer.phar)" ] || [ -z "$(type -t composer)" ]
    then
        composerCmd='composer.phar'
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
