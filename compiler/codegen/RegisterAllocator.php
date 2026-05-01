<?php

class RegisterAllocator {
    // Usaremos de x0 a x15 para propósitos generales
    private array $available = [
        'x0', 'x1', 'x2', 'x3', 'x4', 'x5', 'x6', 'x7',
        'x8', 'x9', 'x10', 'x11', 'x12', 'x13', 'x14', 'x15'
    ];
    private array $used = [];

    public function allocate(): string {
        if (empty($this->available)) {
            throw new Exception("No hay registros disponibles ");
        }
        $reg = array_shift($this->available);
        $this->used[] = $reg;
        return $reg;
    }

    public function free(string $reg) {
        if (($key = array_search($reg, $this->used)) !== false) {
            unset($this->used[$key]);
            $this->available[] = $reg;
        }
    }

    // Convierte x0 -> w0 si necesitas 32 bits
    public function to32(string $reg): string {
        return str_replace('x', 'w', $reg);
    }
}