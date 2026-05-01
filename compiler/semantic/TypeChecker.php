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
        if ($leftType !== $rightType) {
            return null; // No puedes comparar un int32 con un string
        }
        return 'bool';
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
        // nil se puede asignar a punteros 
        if ($actualType === 'nil' && str_contains($expectedType, '*')) {
            return true;
        }
        return $expectedType === $actualType;
    }
}