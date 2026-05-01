<?php

class AsmWriter {
    private string $content = "";

    public function writeLine(string $line, int $indent = 1) {
        $tabs = str_repeat("\t", $indent);
        $this->content .= $tabs . $line . "\n";
    }

    public function writeLabel(string $label) {
        $this->content .= $label . ":\n";
    }

    public function writeComment(string $comment) {
        $this->content .= "\t# " . $comment . "\n";
    }

    public function addRaw(string $text) {
        $this->content .= $text;
    }

    public function getContent(): string {
        return $this->content;
    }
}