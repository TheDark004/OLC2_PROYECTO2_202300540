<?php

class RegisterAllocator {
    // Usaremos de x0 a x15 para propósitos generales
    private array $available = [
        'x0', 'x1', 'x2', 'x3', 'x4', 'x5', 'x6', 'x7',
        'x8', 'x9', 'x10', 'x11', 'x12', 'x13', 'x14', 'x15'
    ];
    private array $used = [];
    private array $reserved = [];

    public function allocate(): string {
        if (empty($this->available)) {
            throw new Exception("No hay registros disponibles ");
        }
        $reg = array_shift($this->available);
        $this->used[] = $reg;
        return $reg;
    }

    public function allocateAvoid(array $avoid): string {
        foreach ($this->available as $idx => $reg) {
            if (!in_array($reg, $avoid, true)) {
                array_splice($this->available, $idx, 1);
                $this->used[] = $reg;
                return $reg;
            }
        }
        throw new Exception("No hay registros disponibles que eviten " . implode(', ', $avoid));
    }

    public function getUsed(): array {
        return array_values($this->used);
    }

    public function free(string $reg) {
        if (($key = array_search($reg, $this->used)) !== false) {
            unset($this->used[$key]);
            $this->available[] = $reg;
        }
    }

    public function temporarilyFree(string $reg): void {
        if (($key = array_search($reg, $this->used)) !== false) {
            unset($this->used[$key]);
            $this->available[] = $reg;
        }
    }

    public function restoreTemporarilyFreed(string $reg): void {
        if (($key = array_search($reg, $this->available)) !== false) {
            unset($this->available[$key]);
            $this->used[] = $reg;
        }
    }

    public function reserve(array $regs): void {
        foreach ($regs as $reg) {
            if (($key = array_search($reg, $this->available)) !== false) {
                unset($this->available[$key]);
                $this->reserved[] = $reg;
            }
        }
    }

    public function unreserve(array $regs): void {
        foreach ($regs as $reg) {
            if (($key = array_search($reg, $this->reserved)) !== false) {
                unset($this->reserved[$key]);
                $this->available[] = $reg;
            }
        }
    }

    // Convierte x0 -> w0 si necesitas 32 bits
    public function to32(string $reg): string {
        return str_replace('x', 'w', $reg);
    }
}