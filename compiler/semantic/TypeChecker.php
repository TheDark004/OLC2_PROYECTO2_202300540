<?php

class TypeChecker {
    
    private array $validTypes = ['int32', 'float32', 'bool', 'rune', 'string'];

    public function isValidType(string $type): bool {
        return in_array(str_replace('*', '', $type), $this->validTypes);
    }

    /**
     * Valida operaciones aritméticas (+, -, *, /, %)
     * Retorna el tipo resultante o null si hay error.
     */
    public function checkArithmetic(string $op, string $leftType, string $rightType): ?string {
        // En Golampi (y Go), los tipos deben ser exactamente iguales para operar
        if ($leftType !== $rightType) {
            return null; 
        }

        if (in_array($leftType, ['int32', 'float32', 'rune'])) {
            return $leftType;
        }

        // Concatenación de strings
        if ($leftType === 'string' && $op === '+') {
            return 'string';
        }

        return null;
    }

    /**
     * Valida operaciones relacionales (<, >, <=, >=, ==, !=)
     * Siempre retornan 'bool'.
     */
    public function checkRelational(string $op, string $leftType, string $rightType): ?string {
        // Comparaciones entre el mismo tipo siempre son válidas.
        if ($leftType === $rightType) {
            return 'bool';
        }

        // nil se puede comparar con cualquier puntero.
        if ($leftType === 'nil' && str_starts_with($rightType, '*')) {
            return 'bool';
        }
        if ($rightType === 'nil' && str_starts_with($leftType, '*')) {
            return 'bool';
        }

        return null; // Comparación inválida entre tipos distintos.
    }

    /**
     * Valida operaciones lógicas (&&, ||)
     * Ambos lados deben ser booleanos y el resultado es booleano.
     */
    public function checkLogical(string $op, string $leftType, string $rightType): ?string {
        if ($leftType === 'bool' && $rightType === 'bool') {
            return 'bool';
        }
        return null;
    }

    /**
     * Valida asignaciones (ej: var x int32 = 10)
     */
    public function checkAssignment(string $expectedType, string $actualType): bool {
        // 1. Tipos idénticos siempre son válidos
        if ($expectedType === $actualType) {
            return true;
        }

        // 2. Familia de enteros (int y int32 son compatibles entre sí)
        if (($expectedType === 'int' || $expectedType === 'int32') && 
            ($actualType === 'int' || $actualType === 'int32')) {
            return true;
        }

        // ... deja el resto del código que ya tenías (como el de 'nil' y punteros) ...
        if ($actualType === 'nil' && str_contains($expectedType, '*')) {
            return true;
        }
        
        return false;
    }
}