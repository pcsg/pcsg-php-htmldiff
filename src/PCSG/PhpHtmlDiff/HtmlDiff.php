<?php

namespace PCSG\PhpHtmlDiff;

class HtmlDiff
{
    private $content;
    private $oldText;
    private $newText;
    private $oldWords = [];
    private $newWords = [];
    private $wordIndices;
    private $encoding;
    private $specialCaseOpeningTags = [
        "/<strong[^>]*/i",
        "/<b[^>]*/i",
        "/<i[^>]*/i",
        "/<big[^>]*/i",
        "/<small[^>]*/i",
        "/<u[^>]*/i",
        "/<sub[^>]*/i",
        "/<sup[^>]*/i",
        "/<strike[^>]*/i",
        "/<s[^>]*/i",
        '/<p[^>]*/i'
    ];
    private $specialCaseClosingTags = [
        "</strong>",
        "</b>",
        "</i>",
        "</big>",
        "</small>",
        "</u>",
        "</sub>",
        "</sup>",
        "</strike>",
        "</s>",
        '</p>'
    ];

    public function __construct($oldText, $newText, $encoding = 'UTF-8')
    {
        $this->oldText  = $this->purifyHtml(trim($oldText));
        $this->newText  = $this->purifyHtml(trim($newText));
        $this->encoding = $encoding;
        $this->content  = '';
    }

    public function getOldHtml()
    {
        return $this->oldText;
    }

    public function getNewHtml()
    {
        return $this->newText;
    }

    public function getDifference()
    {
        return $this->content;
    }

    private function getStringBetween($str, $start, $end)
    {
        $expStr = explode($start, $str, 2);
        if (count($expStr) > 1) {
            $expStr = explode($end, $expStr[1]);
            if (count($expStr) > 1) {
                array_pop($expStr);

                return implode($end, $expStr);
            }
        }

        return '';
    }

    private function purifyHtml($html, $tags = null)
    {
        if (class_exists('Tidy') && false) {
            $config = ['output-xhtml' => true, 'indent' => false];
            $tidy   = new tidy;
            $tidy->parseString($html, $config, 'utf8');
            $html = ( string )$tidy;

            return $this->getStringBetween($html, '<body>');
        }

        return $html;
    }

    public function build()
    {
        $this->splitInputsToWords();
        $this->indexNewWords();
        $operations = $this->operations();
        foreach ($operations as $item) {
            $this->performOperation($item);
        }

        return $this->content;
    }

    private function indexNewWords()
    {
        $this->wordIndices = [];
        foreach ($this->newWords as $i => $word) {
            if ($this->isTag($word)) {
                $word = $this->stripTagAttributes($word);
            }
            if (isset($this->wordIndices[$word])) {
                $this->wordIndices[$word][] = $i;
            } else {
                $this->wordIndices[$word] = [$i];
            }
        }
    }

    private function splitInputsToWords()
    {
        $this->oldWords = $this->convertHtmlToListOfWords($this->explode($this->oldText));
        $this->newWords = $this->convertHtmlToListOfWords($this->explode($this->newText));
    }

    private function convertHtmlToListOfWords($characterString)
    {
        $mode         = 'character';
        $current_word = '';
        $words        = [];
        foreach ($characterString as $character) {
            switch ($mode) {
                case 'character':
                    if ($this->isStartOfTag($character)) {
                        if ($current_word != '') {
                            $words[] = $current_word;
                        }
                        $current_word = "<";
                        $mode         = 'tag';
                    } else {
                        if (preg_match("[^\s]", $character) > 0) {
                            if ($current_word != '') {
                                $words[] = $current_word;
                            }
                            $current_word = $character;
                            $mode         = 'whitespace';
                        } else {
                            if ($this->isAlphaNum($character)
                                && (strlen($current_word) == 0 || $this->isAlphaNum($current_word))
                            ) {
                                $current_word .= $character;
                            } else {
                                $words[]      = $current_word;
                                $current_word = $character;
                            }
                        }
                    }
                    break;
                case 'tag':
                    if ($this->isEndOfTag($character)) {
                        $current_word .= ">";
                        $words[]      = $current_word;
                        $current_word = "";

                        if (!preg_match('[^\s]', $character)) {
                            $mode = 'whitespace';
                        } else {
                            $mode = 'character';
                        }
                    } else {
                        $current_word .= $character;
                    }
                    break;
                case 'whitespace':
                    if ($this->isStartOfTag($character)) {
                        if ($current_word != '') {
                            $words[] = $current_word;
                        }
                        $current_word = "<";
                        $mode         = 'tag';
                    } else {
                        if (preg_match("[^\s]", $character)) {
                            $current_word .= $character;
                        } else {
                            if ($current_word != '') {
                                $words[] = $current_word;
                            }
                            $current_word = $character;
                            $mode         = 'character';
                        }
                    }
                    break;
                default:
                    break;
            }
        }
        if ($current_word != '') {
            $words[] = $current_word;
        }

        return $words;
    }

    private function isStartOfTag($val)
    {
        return $val == "<";
    }

    private function isEndOfTag($val)
    {
        return $val == ">";
    }

    private function isWhiteSpace($value)
    {
        return !preg_match('[^\s]', $value);
    }

    private function isAlphaNum($value)
    {
        return preg_match('/[\p{L}\p{N}]+/u', $value);
    }

    private function explode($value)
    {
        // as suggested by @onassar
        return preg_split('//u', $value);
    }

    private function performOperation($operation)
    {
        switch ($operation->Action) {
            case 'equal':
                $this->processEqualOperation($operation);
                break;
            case 'delete':
                $this->processDeleteOperation($operation, "diffdel");
                break;
            case 'insert':
                $this->processInsertOperation($operation, "diffins");
                break;
            case 'replace':
                $this->processReplaceOperation($operation);
                break;
            default:
                break;
        }
    }

    private function processReplaceOperation($operation)
    {
        $this->processDeleteOperation($operation, "diffmod");
        $this->processInsertOperation($operation, "diffmod");
    }

    private function processInsertOperation($operation, $cssClass)
    {
        $text = [];
        foreach ($this->newWords as $pos => $s) {
            if ($pos >= $operation->StartInNew && $pos < $operation->EndInNew) {
                $text[] = $s;
            }
        }
        $this->insertTag("ins", $cssClass, $text);
    }

    private function processDeleteOperation($operation, $cssClass)
    {
        $text = [];
        foreach ($this->oldWords as $pos => $s) {
            if ($pos >= $operation->StartInOld && $pos < $operation->EndInOld) {
                $text[] = $s;
            }
        }
        $this->insertTag("del", $cssClass, $text);
    }

    private function processEqualOperation($operation)
    {
        $result = [];
        foreach ($this->newWords as $pos => $s) {
            if ($pos >= $operation->StartInNew && $pos < $operation->EndInNew) {
                $result[] = $s;
            }
        }
        $this->content .= implode("", $result);
    }

    private function insertTag($tag, $cssClass, &$words)
    {
        while (true) {
            if (count($words) == 0) {
                break;
            }

            $nonTags = $this->extractConsecutiveWords($words, 'noTag');

            $specialCaseTagInjection         = '';
            $specialCaseTagInjectionIsBefore = false;

            if (count($nonTags) != 0) {
                $text          = $this->wrapText(implode("", $nonTags), $tag, $cssClass);
                $this->content .= $text;
            } else {
                $firstOrDefault = false;
                foreach ($this->specialCaseOpeningTags as $x) {
                    if (preg_match($x, $words[0])) {
                        $firstOrDefault = $x;
                        break;
                    }
                }
                if ($firstOrDefault) {
                    $specialCaseTagInjection = '<ins class="mod">';
                    if ($tag == "del") {
                        unset($words[0]);
                    }
                } else {
                    if (array_search($words[0], $this->specialCaseClosingTags) !== false) {
                        $specialCaseTagInjection         = "</ins>";
                        $specialCaseTagInjectionIsBefore = true;
                        if ($tag == "del") {
                            unset($words[0]);
                        }
                    }
                }
            }
            if (count($words) == 0 && $specialCaseTagInjection == '') {
                break;
            }
            if ($specialCaseTagInjectionIsBefore) {
                $this->content .= $specialCaseTagInjection . implode("", $this->extractConsecutiveWords($words, 'tag'));
            } else {
                $workTag = $this->extractConsecutiveWords($words, 'tag');
                if (isset($workTag[0]) && $this->isOpeningTag($workTag[0]) && !$this->isClosingTag($workTag[0])) {
                    if (strpos($workTag[0], 'class=')) {
                        $workTag[0] = str_replace('class="', 'class="diffmod ', $workTag[0]);
                        $workTag[0] = str_replace("class='", 'class="diffmod ', $workTag[0]);
                    } else {
                        $workTag[0] = str_replace(">", ' class="diffmod">', $workTag[0]);
                    }
                }
                $this->content .= implode("", $workTag) . $specialCaseTagInjection;
            }
        }
    }

    private function checkCondition($word, $condition)
    {
        return $condition == 'tag' ? $this->isTag($word) : !$this->isTag($word);
    }

    private function wrapText($text, $tagName, $cssClass)
    {
        return sprintf('<%1$s class="%2$s">%3$s</%1$s>', $tagName, $cssClass, $text);
    }

    private function extractConsecutiveWords(&$words, $condition)
    {
        $indexOfFirstTag = null;
        foreach ($words as $i => $word) {
            if (!$this->checkCondition($word, $condition)) {
                $indexOfFirstTag = $i;
                break;
            }
        }
        if ($indexOfFirstTag !== null) {
            $items = [];
            foreach ($words as $pos => $s) {
                if ($pos >= 0 && $pos < $indexOfFirstTag) {
                    $items[] = $s;
                }
            }
            if ($indexOfFirstTag > 0) {
                array_splice($words, 0, $indexOfFirstTag);
            }

            return $items;
        } else {
            $items = [];
            foreach ($words as $pos => $s) {
                if ($pos >= 0 && $pos <= count($words)) {
                    $items[] = $s;
                }
            }
            array_splice($words, 0, count($words));

            return $items;
        }
    }

    private function isTag($item)
    {
        return $this->isOpeningTag($item) || $this->isClosingTag($item);
    }

    private function isOpeningTag($item)
    {
        return preg_match("#<[^>]+>\\s*#iU", $item);
    }

    private function isClosingTag($item)
    {
        return preg_match("#</[^>]+>\\s*#iU", $item);
    }

    private function operations()
    {
        $positionInOld = 0;
        $positionInNew = 0;
        $operations    = [];
        $matches       = $this->matchingBlocks();
        $matches[]     = new MatchData(count($this->oldWords), count($this->newWords), 0);
        foreach ($matches as $i => $match) {
            $matchStartsAtCurrentPositionInOld = ($positionInOld == $match->StartInOld);
            $matchStartsAtCurrentPositionInNew = ($positionInNew == $match->StartInNew);
            $action                            = 'none';

            if ($matchStartsAtCurrentPositionInOld == false && $matchStartsAtCurrentPositionInNew == false) {
                $action = 'replace';
            } else {
                if ($matchStartsAtCurrentPositionInOld == true && $matchStartsAtCurrentPositionInNew == false) {
                    $action = 'insert';
                } else {
                    if ($matchStartsAtCurrentPositionInOld == false && $matchStartsAtCurrentPositionInNew == true) {
                        $action = 'delete';
                    } else { // This occurs if the first few words are the same in both versions
                        $action = 'none';
                    }
                }
            }
            if ($action != 'none') {
                $operations[] = new Operation(
                    $action,
                    $positionInOld,
                    $match->StartInOld,
                    $positionInNew,
                    $match->StartInNew
                );
            }
            if (count($match) != 0) {
                $operations[] = new Operation(
                    'equal',
                    $match->StartInOld,
                    $match->endInOld(),
                    $match->StartInNew,
                    $match->endInNew()
                );
            }
            $positionInOld = $match->endInOld();
            $positionInNew = $match->endInNew();
        }

        return $operations;
    }

    private function matchingBlocks()
    {
        $matchingBlocks = [];
        $this->findMatchingBlocks(0, count($this->oldWords), 0, count($this->newWords), $matchingBlocks);

        return $matchingBlocks;
    }

    private function findMatchingBlocks($startInOld, $endInOld, $startInNew, $endInNew, &$matchingBlocks)
    {
        $match = $this->findMatch($startInOld, $endInOld, $startInNew, $endInNew);
        if ($match !== null) {
            if ($startInOld < $match->StartInOld && $startInNew < $match->StartInNew) {
                $this->findMatchingBlocks(
                    $startInOld,
                    $match->StartInOld,
                    $startInNew,
                    $match->StartInNew,
                    $matchingBlocks
                );
            }
            $matchingBlocks[] = $match;
            if ($match->endInOld() < $endInOld && $match->endInNew() < $endInNew) {
                $this->findMatchingBlocks(
                    $match->endInOld(),
                    $endInOld,
                    $match->endInNew(),
                    $endInNew,
                    $matchingBlocks
                );
            }
        }
    }

    private function stripTagAttributes($word)
    {
        $word = explode(' ', trim($word, '<>'));

        return '<' . $word[0] . '>';
    }

    private function findMatch($startInOld, $endInOld, $startInNew, $endInNew)
    {
        $bestMatchInOld = $startInOld;
        $bestMatchInNew = $startInNew;
        $bestMatchSize  = 0;
        $matchLengthAt  = [];
        for ($indexInOld = $startInOld; $indexInOld < $endInOld; $indexInOld++) {
            $newMatchLengthAt = [];
            $index            = $this->oldWords[$indexInOld];
            if ($this->isTag($index)) {
                $index = $this->stripTagAttributes($index);
            }
            if (!isset($this->wordIndices[$index])) {
                $matchLengthAt = $newMatchLengthAt;
                continue;
            }
            foreach ($this->wordIndices[$index] as $indexInNew) {
                if ($indexInNew < $startInNew) {
                    continue;
                }
                if ($indexInNew >= $endInNew) {
                    break;
                }
                $newMatchLength                = ($matchLengthAt[$indexInNew - 1] ?? 0) + 1;
                $newMatchLengthAt[$indexInNew] = $newMatchLength;
                if ($newMatchLength > $bestMatchSize) {
                    $bestMatchInOld = $indexInOld - $newMatchLength + 1;
                    $bestMatchInNew = $indexInNew - $newMatchLength + 1;
                    $bestMatchSize  = $newMatchLength;
                }
            }
            $matchLengthAt = $newMatchLengthAt;
        }

        return $bestMatchSize != 0 ? new MatchData($bestMatchInOld, $bestMatchInNew, $bestMatchSize) : null;
    }
}
