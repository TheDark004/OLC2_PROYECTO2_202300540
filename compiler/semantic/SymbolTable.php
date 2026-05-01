<?php

class Symbol {
    public string $name;
    public string $type;
    public int $offset; 
    public ?array $arrayDims;
    public bool $isConst;
    public bool $isPointer;
    public string $scope;
    public bool $isParameter;
    public mixed $value;

    public function __construct(string $name, string $type, int $offset, ?array $arrayDims = null, bool $isConst = false, bool $isPointer = false, string $scope = 'global', bool $isParameter = false) {
        $this->name = $name;
        $this->type = $type;
        $this->offset = $offset;
        $this->arrayDims = $arrayDims;
        $this->isConst = $isConst;
        $this->isPointer = $isPointer;
        $this->scope = $scope;
        $this->isParameter = $isParameter;
        $this->value = null;
    }
}

class Scope {
    public string $name;
    public ?Scope $parent;
    public array $symbols = [];
    public int $currentStackOffset = 0; // Se reinicia en 0 para cada nueva función

    public function __construct(string $name, ?Scope $parent = null) {
        $this->name = $name;
        $this->parent = $parent;
        
        // Si es un bloque anidado (ej. un if/for), hereda el offset de la función actual
        if ($parent !== null && $name !== 'global' && $parent->name !== 'global') {
            $this->currentStackOffset = $parent->currentStackOffset;
        }
    }

    public function addSymbol(string $name, string $type, ?array $arrayDims = null, bool $isConst = false, bool $isPointer = false, bool $isParameter = false): Symbol {
        if (array_key_exists($name, $this->symbols)) {
            throw new Exception("El identificador '{$name}' ya ha sido declarado en el ámbito actual.");
        }

        // 1. Calculamos el tamaño en bytes
        $size = SymbolTable::getTypeSize($type, $arrayDims, $isPointer);
        
        // 2. ARM64 Stack crece hacia abajo (offsets negativos).
        // Los parámetros llegan en registros, pero se guardan en el frame
        // para que el resto del compilador pueda leerlos como variables.
        if ($this->name !== 'global') {
            $this->currentStackOffset -= $size;
        }

        // 3. Registramos el símbolo
        $symbol = new Symbol($name, $type, $this->currentStackOffset, $arrayDims, $isConst, $isPointer, $this->name, $isParameter);
        $this->symbols[$name] = $symbol;
        
        return $symbol;
    }

    public function getSymbol(string $name): ?Symbol {
        if (array_key_exists($name, $this->symbols)) {
            return $this->symbols[$name];
        }
        if ($this->parent !== null) {
            return $this->parent->getSymbol($name);
        }
        return null;
    }
}

class SymbolTable {
    private ?Scope $currentScope = null;
    private array $allScopes = [];
    public array $functionsStackSize = []; // Guarda la alineación de 16 bytes de cada función

    public function __construct() {
        $this->pushScope('global');
    }

    public function pushScope(string $name): void {
        $newScope = new Scope($name, $this->currentScope);
        $this->currentScope = $newScope;
        $this->allScopes[] = $newScope;
    }

    public function popScope(): void {
        if ($this->currentScope !== null && $this->currentScope->parent !== null) {
            // Si salimos de un bloque anidado, el padre actualiza su offset para no sobreescribir memoria
            $this->currentScope->parent->currentStackOffset = $this->currentScope->currentStackOffset;
            $this->currentScope = $this->currentScope->parent;
        }
    }

    public function getCurrentScope(): ?Scope {
        return $this->currentScope;
    }

    public function getAllSymbols(): array {
        $flat = [];
        foreach ($this->allScopes as $scope) {
            foreach ($scope->symbols as $sym) {
                $flat[] = $sym;
            }
        }
        return $flat;
    }

    /**
     * Calcula el tamaño en bytes de un tipo para ARM64.
     */
    public static function getTypeSize(string $type, ?array $dims = null, bool $isPointer = false): int {
        if ($isPointer || str_contains($type, '*')) {
            return 8; // Punteros de 64 bits en ARM64
        }

        $baseSize = match(str_replace('*', '', $type)) {
            'int32', 'int', 'bool', 'rune', 'float32' => 4,
            'string' => 8, // En el stack guardamos la referencia a memoria
            default => 8
        };

        // Si es arreglo N-D, multiplicar dimensiones por el tamaño base
        if (!empty($dims)) {
            $totalElements = 1;
            foreach ($dims as $dim) {
                $totalElements *= (int)$dim;
            }
            return $totalElements * $baseSize;
        }

        return $baseSize;
    }

    /**
     * Devuelve el tamaño total del stack alineado a 16 bytes para una función.
     */
    public function calculateAlignedStackSize(string $funcName): int {
        $size = abs($this->currentScope->currentStackOffset);
        // Fórmula de alineación a 16 bytes requerida por AArch64
        $alignedSize = ($size + 15) & ~15; 
        $this->functionsStackSize[$funcName] = $alignedSize;
        return $alignedSize;
    }
}
