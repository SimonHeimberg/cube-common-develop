#!/bin/bash

# encourage developer to use the recommended netbeans settings

if [ -d nbproject ] || [ -d ~/.netbeans/ ] || type netbeans 2>&1 >/dev/null
then # netbeans is installed
    nbUrl=https://github.com/SimonHeimberg/nbproject_4cube
    if [ -d nbproject/.git ]
    then # git repo in place
        git -C nbproject fetch
        git -C nbproject merge --ff-only
    elif [ ! -d nbproject ]
    then # nothing there
        git clone $nbUrl nbproject
    else # settings there
        atExit() {
            [ -d .tmp_nbproject ] && mv -b .tmp_nbproject /tmp/tmp_nbproject
        }
        trap 'atExit' EXIT
        git clone $nbUrl .tmp_nbproject &&
        mv -i .tmp_nbproject/.git nbproject && # place repo into config
        rm -r .tmp_nbproject
        git -C nbproject checkout -- $(git -C nbproject diff --name-only --diff-filter=D)
        git -C nbproject --no-pager diff --exit-code || echo check your netbeans configuration
    fi
    cp -n nbproject/project.xml.dist nbproject/project.xml # copy project xml if it does not exist
fi

installGitHook () {
    checkCommitArg=$1
    if [ '--nocc' != "$checkCommitArg" ] && [ -d .git ]
    then
        if [ -z "$checkCommitArg" ]
        then
            checkStyle="$(dirname $BASH_SOURCE)/../CodeStyle/check-commit-cube.sh"
        else
            checkStyle="$checkCommitArg"
        fi
        [ -f .git/hooks/pre-commit ] || cp -n "$checkStyle" .git/hooks/pre-commit
    fi
}
installGitHook $1
