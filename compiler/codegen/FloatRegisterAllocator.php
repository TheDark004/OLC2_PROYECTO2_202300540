<?php

class FloatRegisterAllocator {
    private array $available = ['s0', 's1', 's2', 's3', 's4', 's5', 's6', 's7',
                                 's8', 's9', 's10', 's11', 's12', 's13', 's14', 's15'];
    private array $used = [];

    public function allocate(): string {
        if (empty($this->available)) {
            throw new Exception("No hay registros flotantes disponibles");
        }
        $reg = array_shift($this->available);
        $this->used[] = $reg;
        return $reg;
    }

    public function free(string $reg): void {
        if (($key = array_search($reg, $this->used)) !== false) {
            unset($this->used[$key]);
            $this->available[] = $reg;
        }
    }

    public function getUsed(): array {
        return array_values($this->used);
    }

    public function reset(): void {
        $this->available = ['s0', 's1', 's2', 's3', 's4', 's5', 's6', 's7',
                             's8', 's9', 's10', 's11', 's12', 's13', 's14', 's15'];
        $this->used = [];
    }
}
?>
