<?php

class LabelManager {
    private int $counter = 0;

    public function newLabel(string $prefix = "L"): string {
        $this->counter++;
        return "{$prefix}_" . $this->counter;
    }
}