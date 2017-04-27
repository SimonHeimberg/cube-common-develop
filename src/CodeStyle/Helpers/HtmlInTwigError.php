<?php

namespace CubeTools\CubeCommonDevelop\CodeStyle\Helpers;

class HtmlInTwigError extends \LibXMLError
{
    public $ctxLine;

    public function __construct(parent $xmlErr)
    {
        foreach (get_object_vars($xmlErr) as $key => $value) {
            $this->$key = $value;
        }
    }

    public function getErrLevel()
    {
        return array(LIBXML_ERR_WARNING => 'W', LIBXML_ERR_ERROR => 'E', LIBXML_ERR_FATAL => 'F')[$this->level];
    }

    public function getContextA()
    {
        return substr($this->ctxLine, 0, $this->column - 1);
    }

    public function getContextE()
    {
        $r = substr($this->ctxLine, $this->column - 1, 1);

        return $r ?: '  ';
    }

    public function getContextZ()
    {
        return substr($this->ctxLine, $this->column);
    }

    public function getFormatedContext($startTag = '<error>', $endTag = '</error>')
    {
        $msg = $this->ctxLine;
        $msg = substr_replace($msg, $endTag, $this->column + 1, 0);
        $msg = substr_replace($msg, $startTag, $this->column, 0);

        return $msg;
    }
}
