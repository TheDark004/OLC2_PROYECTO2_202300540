<?php


class SemanticVisitor extends GolampiBaseVisitor {
    public SymbolTable $symbolTable;
    public TypeChecker $typeChecker; 
    public array $errors = [];
    private array $functions = []; // Para el Hoisting de funciones
    public array $functionSignatures = [];
    private array $currentFunctionReturns = [];

    public function __construct() {
        $this->symbolTable = new SymbolTable();
        $this->typeChecker = new TypeChecker(); 
    }

    public function visitP($ctx) {
        // Primera pasada: Hoisting de funciones globales
        $this->hoistFunctions($ctx);

        // Segunda pasada: Análisis semántico de todo el código
        foreach ($ctx->decl() as $decl) {
            $this->visit($decl);
        }

        // Validar existencia obligatoria de la función main
        if (!isset($this->functions['main'])) {
            $this->addError("No se encontró la función 'main'.", $ctx);
        }

        return null;
    }

    private function hoistFunctions($ctx) {
        foreach ($ctx->decl() as $decl) {
            if ($decl->funcDecl() !== null) {
                $funcName = $decl->funcDecl()->ID()->getText();
                if (isset($this->functions[$funcName])) {
                    $this->addError("La función '{$funcName}' ya ha sido declarada previamente.", $decl);
                } else {
                    $funcCtx = $decl->funcDecl();
                    $this->functions[$funcName] = $funcCtx;
                    $this->functionSignatures[$funcName] = [
                        'params' => $this->getParamTypes($funcCtx),
                        'returns' => $this->getReturnTypes($funcCtx),
                    ];
                }
            }
        }
    }

    // Funciones Void
    public function visitFuncDeclVoid($ctx) {
        $this->processFunctionDeclaration($ctx);
        return null;
    }

    // Funciones con Retorno Simple
    public function visitFuncDeclReturn($ctx) {
        $this->processFunctionDeclaration($ctx);
        return null;
    }

    // Funciones con Múltiples Retornos
    public function visitFuncDeclMultiReturn($ctx) {
        $this->processFunctionDeclaration($ctx);
        return null;
    }

    /**
     * Lógica centralizada para procesar cualquier tipo de función
     */
    private function processFunctionDeclaration($ctx) {
        $funcName = $ctx->ID()->getText();
        $previousReturns = $this->currentFunctionReturns;
        $this->currentFunctionReturns = $this->getReturnTypes($ctx);
        
       
        $this->symbolTable->pushScope($funcName);

     
        if ($ctx->param() !== null) {
            foreach ($ctx->param() as $paramCtx) {
                $this->procesarParametroGeneral($paramCtx);
            }
        }

       
        $this->visit($ctx->block());

    
        $this->symbolTable->calculateAlignedStackSize($funcName);
        $this->symbolTable->popScope();
        $this->currentFunctionReturns = $previousReturns;
    }

    private function getParamTypes($funcCtx): array {
        $types = [];
        if (!method_exists($funcCtx, 'param')) {
            return $types;
        }

        foreach (($funcCtx->param() ?? []) as $paramCtx) {
            $types[] = $this->paramTypeToText($paramCtx);
        }
        return $types;
    }

    

    private function getReturnTypes($funcCtx): array {
        if (!method_exists($funcCtx, 'returnType')) {
            return [];
        }

        $types = [];
        $returnTypeContexts = $funcCtx->returnType() ?? [];
        if ($returnTypeContexts !== [] && !is_array($returnTypeContexts)) {
            $returnTypeContexts = [$returnTypeContexts];
        }

        foreach ($returnTypeContexts as $returnTypeCtx) {
            $types[] = $this->typeContextToText($returnTypeCtx);
        }
        return $types;
    }

    private function paramTypeToText($paramCtx): string {
        $type = $paramCtx->type_()->getText();
        if (method_exists($paramCtx, 'arrayType') && $paramCtx->arrayType() !== null) {
            $type = $paramCtx->arrayType()->getText() . $type;
        }
        if (str_contains($paramCtx->getText(), '*')) {
            $type = '*' . ltrim($type, '*');
        }
        return $type;
    }

    private function typeContextToText($typeCtx): string {
        $type = $typeCtx->type_()->getText();
        if (method_exists($typeCtx, 'arrayType') && $typeCtx->arrayType() !== null) {
            return $typeCtx->arrayType()->getText() . $type;
        }
        return $type;
    }

    private function isMultiType(mixed $type): bool {
        return is_array($type);
    }

    private function typeForSingleValue(mixed $type, $ctx): string {
        if ($this->isMultiType($type)) {
            if (count($type) === 1) {
                return $type[0];
            }
            $this->addError("La expresión devuelve múltiples valores; usa una declaración/asignación múltiple.", $ctx);
            return 'unknown';
        }
        return $type ?? 'unknown';
    }

    public function visitParametro($ctx) {
        $this->procesarParametroGeneral($ctx);
        return null;
    }

    public function visitConstDeclStmt($ctx) {
        $name = $ctx->ID()->getText();
        $type = $ctx->type_()->getText();

        try {
            $rightType = $this->typeForSingleValue($this->visit($ctx->e()), $ctx);
            if ($rightType !== 'unknown' && !$this->typeChecker->checkAssignment($type, $rightType)) {
                $this->addError("No se puede inicializar la constante '{$name}' de tipo '{$type}' con un valor de tipo '{$rightType}'.", $ctx);
            }

            $this->symbolTable->getCurrentScope()->addSymbol($name, $type, null, true);
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), $ctx);
        }

        return null;
    }

    // --- MANEJO DE VARIABLES Y ARREGLOS ---
    public function visitVarDeclInit($ctx) {
        $name = $ctx->ID()->getText(); // Tomamos el primer ID
        $type = $ctx->type_()->getText();
        try {
            // Revisamos qué tipo intenta guardar (evaluamos la expresión)
            $rightType = $this->typeForSingleValue($this->visit($ctx->e()), $ctx);
            
            // Verificamos si los tipos son compatibles
            if ($rightType !== 'unknown' && !$this->typeChecker->checkAssignment($type, $rightType)) {
                $this->addError("No se puede inicializar la variable '{$name}' de tipo '{$type}' con un valor de tipo '{$rightType}'.", $ctx);
            }

            $this->symbolTable->getCurrentScope()->addSymbol($name, $type);
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), $ctx);
        }
        return null;
    }

    public function visitVarDeclEmpty($ctx) {
        $name = $ctx->ID()->getText();
        $type = $ctx->type_()->getText();

        try {
            $this->symbolTable->getCurrentScope()->addSymbol($name, $type);
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), $ctx);
        }

        return null;
    }

    public function visitShortVarDecl($ctx) {
        $name = $ctx->ID()->getText();
        
        try {
            
            $rightType = $this->typeForSingleValue($this->visit($ctx->e()), $ctx);
            
            if ($rightType === 'unknown') {
                $this->addError("No se puede inferir el tipo para la variable '{$name}'.", $ctx);
                return null;
            }

            
            list($baseType, $dims) = $this->extractDimsAndType($rightType);

           
            $this->symbolTable->getCurrentScope()->addSymbol($name, $baseType, $dims);
            
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), $ctx);
        }
        return null;
    }

    public function visitVarArrayND($ctx) {
        $name = $ctx->ID()->getText();
        $type = $ctx->type_()->getText();
        
        $dims = [];
        $arrayTypeCtx = $ctx->arrayType();
        foreach ($arrayTypeCtx->INT_LIT() as $dimNode) {
            $dims[] = (int)$dimNode->getText();
        }

        try {
            $this->symbolTable->getCurrentScope()->addSymbol($name, $type, $dims);
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), $ctx);
        }
        return null;
    }

    public function visitVarArrayNDInit($ctx) {
        $name = $ctx->ID()->getText();
        $type = $ctx->type_()->getText();
        $dims = [];

        foreach ($ctx->arrayType()->INT_LIT() as $dimNode) {
            $dims[] = (int) $dimNode->getText();
        }

        try {
            $this->symbolTable->getCurrentScope()->addSymbol($name, $type, $dims);
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), $ctx);
        }

        return null;
    }

    public function visitShortVarArrayND($ctx) {
        $name = $ctx->ID()->getText();
        $type = $ctx->arrayLit()->type_()->getText();
        $dims = [];

        foreach ($ctx->arrayLit()->arrayType()->INT_LIT() as $dimNode) {
            $dims[] = (int) $dimNode->getText();
        }

        try {
            $this->symbolTable->getCurrentScope()->addSymbol($name, $type, $dims);
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), $ctx);
        }

        return null;
    }


    
    public function visitParametroArrayND($ctx) {
        $this->procesarParametroGeneral($ctx);
        return null;
    }

    private function procesarParametroGeneral($ctx) {
        $name = $ctx->ID()->getText();
        
        $rawType = $this->paramTypeToText($ctx);
        
        list($baseType, $dims) = $this->extractDimsAndType($rawType);
        
        $isPointer = str_starts_with($rawType, '*');
        
        $baseType = str_replace('*', '', $baseType);

        try {
    
            $this->symbolTable->getCurrentScope()->addSymbol($name, $baseType, $dims, false, $isPointer, true);
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), $ctx);
        }
    }

    // Función ayudante para extraer dimensiones de un string de tipo
    public function extractDimsAndType(string $rawType): array {
        $dims = [];
        if (preg_match_all('/\[(\d+)\]/', $rawType, $matches)) {
            foreach ($matches[1] as $dim) {
                $dims[] = (int)$dim;
            }
            $baseType = preg_replace('/\[\d+\]/', '', $rawType);
            return [$baseType, $dims];
        }
        return [$rawType, null];
    }

   
    public function visitDerefExpr($ctx) {
        $name = $ctx->ID()->getText();
        $symbol = $this->symbolTable->getCurrentScope()->getSymbol($name);

        if ($symbol === null) {
            $this->addError("La variable '{$name}' no ha sido declarada en este ámbito.", $ctx);
            return 'unknown';
        }

        if (!$this->isSymbolPointer($symbol)) {
            $this->addError("No se puede desreferenciar '{$name}' porque no es un puntero.", $ctx);
            return 'unknown';
        }

        return $this->getSymbolBaseTypeString($symbol);
    }

    public function visitRefExpr($ctx) {
        $name = $ctx->ID()->getText();
        $symbol = $this->symbolTable->getCurrentScope()->getSymbol($name);

        if ($symbol === null) {
            $this->addError("La variable '{$name}' no ha sido declarada en este ámbito.", $ctx);
            return 'unknown';
        }

        $typeStr = $this->getSymbolTypeString($symbol);
        return '*' . ltrim($typeStr, '*');
    }

    // --- VERIFICACIONES DE USO ---

    private function getSymbolTypeString($symbol): string {
        $typeStr = $symbol->type;

        if (!empty($symbol->arrayDims)) {
            $arrayStr = '';
            foreach ($symbol->arrayDims as $dim) {
                $arrayStr .= "[$dim]";
            }
            $typeStr = $arrayStr . $typeStr;
        }

        if ($this->isSymbolPointer($symbol) && !str_starts_with($typeStr, '*')) {
            $typeStr = '*' . $typeStr;
        }

        return $typeStr;
    }

    private function getSymbolBaseTypeString($symbol): string {
        $typeStr = $this->getSymbolTypeString($symbol);
        $typeStr = ltrim($typeStr, '*');
        return preg_replace('/^(?:\[\d+\])+/','', $typeStr);
    }

    private function isSymbolPointer($symbol): bool {
        return (property_exists($symbol, 'isPointer') && $symbol->isPointer) || str_starts_with($symbol->type, '*');
    }

    public function visitIdExpr($ctx) {
        $name = $ctx->ID()->getText();
        $symbol = $this->symbolTable->getCurrentScope()->getSymbol($name);
        
        if ($symbol === null) {
            $this->addError("La variable '{$name}' no ha sido declarada en este ámbito.", $ctx);
            return 'unknown';
        }
        
        return $this->getSymbolTypeString($symbol);
    }
    public function visitAssignStmt($ctx) {
        if ($ctx->ID() !== null) {
            $name = $ctx->ID()->getText();
            $symbol = $this->symbolTable->getCurrentScope()->getSymbol($name);
            
            if ($symbol === null) {
                $this->addError("No se puede asignar a '{$name}' porque no está declarada.", $ctx);
                return 'unknown';
            } elseif ($symbol->isConst) {
                $this->addError("No se puede modificar el valor de la constante '{$name}'.", $ctx);
                return 'unknown';
            }

            $rightType = $this->typeForSingleValue($this->visit($ctx->e()), $ctx);
            $expectedType = $this->getSymbolTypeString($symbol);
            
            if ($rightType !== 'unknown' && !$this->typeChecker->checkAssignment($expectedType, $rightType)) {
                $this->addError("Error de tipos: No se puede asignar '{$rightType}' a '{$name}' que es de tipo '{$expectedType}'.", $ctx);
            }
            return null;
        }

     
        if (method_exists($ctx, 'derefExpr') && $ctx->derefExpr() !== null) {
           
            $this->visit($ctx->e());
            return null;
        }

        return null;
    }

    public function visitArrayAccessND($ctx) {
        $name = $ctx->ID()->getText();
        $symbol = $this->symbolTable->getCurrentScope()->getSymbol($name);

        if ($symbol === null) {
            $this->addError("El arreglo '{$name}' no ha sido declarado en este ámbito.", $ctx);
            return 'unknown';
        }

        if (empty($symbol->arrayDims)) {
            $this->addError("El identificador '{$name}' no es un arreglo.", $ctx);
            return 'unknown';
        }

        return $this->getSymbolBaseTypeString($symbol);
    }

    public function visitArrayAssignND($ctx) {
        $name = $ctx->ID()->getText();
        $symbol = $this->symbolTable->getCurrentScope()->getSymbol($name);

        if ($symbol === null) {
            $this->addError("No se puede asignar al arreglo '{$name}' porque no está declarado.", $ctx);
            return null;
        }

        if (empty($symbol->arrayDims)) {
            $this->addError("El identificador '{$name}' no es un arreglo.", $ctx);
            return null;
        }

        $exprs = $ctx->e();
        $valueExpr = $exprs[count($exprs) - 1] ?? null;
        if ($valueExpr !== null) {
            $rightType = $this->typeForSingleValue($this->visit($valueExpr), $valueExpr);
            $expectedType = $this->getSymbolBaseTypeString($symbol);
            if ($rightType !== 'unknown' && !$this->typeChecker->checkAssignment($expectedType, $rightType)) {
                $this->addError("Error de tipos: no se puede asignar '{$rightType}' a un elemento de '{$name}' que es de tipo '{$expectedType}'.", $ctx);
            }
        }

        return null;
    }


    // VALIDACIONES MATEMÁTICAS Y LÓGICAS
    public function visitAddExpr($ctx) {
        $leftType = $this->visit($ctx->e(0));
        $rightType = $this->visit($ctx->e(1));
        $op = $ctx->op->getText(); // Puede ser '+' o '-'

        if ($leftType === 'unknown' || $rightType === 'unknown') return 'unknown';

        $resultType = $this->typeChecker->checkArithmetic($op, $leftType, $rightType);
        
        if ($resultType === null) {
            $this->addError("Operación inválida: '$op' entre '$leftType' y '$rightType'.", $ctx);
            return 'unknown';
        }
        return $resultType; 
    }

    public function visitMulExpr($ctx) {
        $leftType = $this->visit($ctx->e(0));
        $rightType = $this->visit($ctx->e(1));
        $op = $ctx->op->getText(); // '*', '/', '%'

        if ($leftType === 'unknown' || $rightType === 'unknown') return 'unknown';

        $resultType = $this->typeChecker->checkArithmetic($op, $leftType, $rightType);
        
        if ($resultType === null) {
            $this->addError("Operación inválida: '$op' entre '$leftType' y '$rightType'.", $ctx);
            return 'unknown';
        }
        return $resultType; 
    }

    public function visitRelExpr($ctx) {
        $leftType = $this->visit($ctx->e(0));
        $rightType = $this->visit($ctx->e(1));
        $op = $ctx->op->getText(); // '<', '>', '<=', '>='

        if ($leftType === 'unknown' || $rightType === 'unknown') return 'unknown';

        $resultType = $this->typeChecker->checkRelational($op, $leftType, $rightType);
        
        if ($resultType === null) {
            $this->addError("Comparación inválida: '$op' entre '$leftType' y '$rightType'.", $ctx);
            return 'unknown';
        }
        return $resultType; 
    }

    public function visitEqExpr($ctx) {
        return $this->visitRelExpr($ctx); 
    }

    public function visitFuncCallExpr($ctx) {
        return $this->checkFunctionCall($ctx->ID()->getText(), $ctx->e() ?? [], $ctx, true);
    }

    public function visitFuncCallStmt($ctx) {
        $this->checkFunctionCall($ctx->ID()->getText(), $ctx->e() ?? [], $ctx, false);
        return null;
    }

    private function checkFunctionCall(string $name, array $args, $ctx, bool $asExpression): mixed {
        if (!isset($this->functionSignatures[$name])) {
            $this->addError("La función '{$name}' no ha sido declarada.", $ctx);
            return 'unknown';
        }

        $signature = $this->functionSignatures[$name];
        $paramTypes = $signature['params'];
        $returnTypes = $signature['returns'];

        if (count($args) !== count($paramTypes)) {
            $this->addError("La función '{$name}' espera " . count($paramTypes) . " argumento(s), pero recibió " . count($args) . ".", $ctx);
        }

        foreach ($args as $i => $argCtx) {
            $actualType = $this->typeForSingleValue($this->visit($argCtx), $argCtx);
            if (!isset($paramTypes[$i]) || $actualType === 'unknown') {
                continue;
            }
            if (!$this->typeChecker->checkAssignment($paramTypes[$i], $actualType)) {
                $this->addError("Argumento " . ($i + 1) . " inválido en '{$name}': se esperaba '{$paramTypes[$i]}' y se recibió '{$actualType}'.", $argCtx);
            }
        }

        if (!$asExpression) {
            return null;
        }

        if (count($returnTypes) === 0) {
            $this->addError("La función '{$name}' no devuelve ningún valor.", $ctx);
            return 'unknown';
        }

        return count($returnTypes) === 1 ? $returnTypes[0] : $returnTypes;
    }

    public function getFunctionSignatures(): array {
        return $this->functionSignatures;
    }

    public function visitMultiShortVarDecl($ctx) {
        $ids = $ctx->ID();
        $exprs = $ctx->e();
        $types = [];

        if (count($exprs) === 1) {
            $exprType = $this->visit($exprs[0]);
            $types = $this->isMultiType($exprType) ? $exprType : [$exprType];
        } else {
            foreach ($exprs as $exprCtx) {
                $types[] = $this->typeForSingleValue($this->visit($exprCtx), $exprCtx);
            }
        }

        if (count($ids) !== count($types)) {
            $this->addError("Cantidad inválida en declaración múltiple: " . count($ids) . " identificador(es) y " . count($types) . " valor(es).", $ctx);
            return null;
        }

        foreach ($ids as $i => $idNode) {
            $name = $idNode->getText();
            $type = $types[$i] ?? 'unknown';
            if ($type === 'unknown' || $type === null) {
                $this->addError("No se puede inferir el tipo para la variable '{$name}'.", $ctx);
                continue;
            }
            try {
                $this->symbolTable->getCurrentScope()->addSymbol($name, $type);
            } catch (\Exception $e) {
                $this->addError($e->getMessage(), $ctx);
            }
        }

        return null;
    }

    public function visitVarDeclMulti($ctx) {
        $ids = $ctx->ID();
        $exprs = $ctx->e();
        $type = $ctx->type_()->getText();

        if (count($ids) !== count($exprs)) {
            $this->addError("Cantidad inválida en declaración múltiple: " . count($ids) . " identificador(es) y " . count($exprs) . " valor(es).", $ctx);
            return null;
        }

        foreach ($ids as $i => $idNode) {
            $rightType = $this->typeForSingleValue($this->visit($exprs[$i]), $exprs[$i]);
            if ($rightType !== 'unknown' && !$this->typeChecker->checkAssignment($type, $rightType)) {
                $this->addError("No se puede inicializar '{$idNode->getText()}' de tipo '{$type}' con un valor de tipo '{$rightType}'.", $ctx);
            }
            try {
                $this->symbolTable->getCurrentScope()->addSymbol($idNode->getText(), $type);
            } catch (\Exception $e) {
                $this->addError($e->getMessage(), $ctx);
            }
        }

        return null;
    }

    public function visitAndExpr($ctx) {
        $leftType = $this->typeForSingleValue($this->visit($ctx->e(0)), $ctx->e(0));
        $rightType = $this->typeForSingleValue($this->visit($ctx->e(1)), $ctx->e(1));
        
        if ($leftType === 'unknown' || $rightType === 'unknown') return 'unknown';

        $resultType = $this->typeChecker->checkLogical('&&', $leftType, $rightType);
        if ($resultType === null) {
            $this->addError("El operador '&&' requiere que ambos lados sean booleanos (Se recibió '$leftType' y '$rightType').", $ctx);
            return 'unknown';
        }
        return $resultType;
    }

    public function visitOrExpr($ctx) {
        $leftType = $this->typeForSingleValue($this->visit($ctx->e(0)), $ctx->e(0));
        $rightType = $this->typeForSingleValue($this->visit($ctx->e(1)), $ctx->e(1));
        
        if ($leftType === 'unknown' || $rightType === 'unknown') return 'unknown';

        $resultType = $this->typeChecker->checkLogical('||', $leftType, $rightType);
        if ($resultType === null) {
            $this->addError("El operador '||' requiere que ambos lados sean booleanos (Se recibió '$leftType' y '$rightType').", $ctx);
            return 'unknown';
        }
        return $resultType;
    }

    public function visitGroupExpr($ctx) {
        return $this->typeForSingleValue($this->visit($ctx->e()), $ctx);
    }

    public function visitNotExpr($ctx) {
        $exprType = $this->typeForSingleValue($this->visit($ctx->e()), $ctx->e());
        if ($exprType === 'unknown') {
            return 'unknown';
        }

        if ($exprType !== 'bool') {
            $this->addError("El operador '!' requiere una expresión booleana, se recibió '{$exprType}'.", $ctx);
            return 'unknown';
        }

        return 'bool';
    }

    public function visitNegExpr($ctx) {
        $exprType = $this->typeForSingleValue($this->visit($ctx->e()), $ctx->e());
        if ($exprType === 'unknown') {
            return 'unknown';
        }

        if (!in_array($exprType, ['int32', 'int', 'float32', 'rune'], true)) {
            $this->addError("El operador '-' unario requiere un valor numérico, se recibió '{$exprType}'.", $ctx);
            return 'unknown';
        }

        return $exprType;
    }


    //  TIPOS DE LITERALES
    public function visitIntLit($ctx) {
        return 'int32';
    }

    public function visitFloatLit($ctx) {
        return 'float32';
    }

    public function visitBoolLit($ctx) {
        return 'bool';
    }

    public function visitStringLit($ctx) {
        return 'string';
    }

    public function visitRuneLit($ctx) {
        return 'rune';
    }

    public function visitNilLit($ctx) {
        return 'nil';
    }

    public function visitCastInt32($ctx) {
        $this->visit($ctx->e());
        return 'int32';
    }

    public function visitCastInt($ctx) {
        $this->visit($ctx->e());
        return 'int';
    }

    public function visitCastFloat32($ctx) {
        $this->visit($ctx->e());
        return 'float32';
    }

    public function visitCastBool($ctx) {
        $this->visit($ctx->e());
        return 'bool';
    }

    public function visitCastString($ctx) {
        $this->visit($ctx->e());
        return 'string';
    }

    public function visitCastRune($ctx) {
        $this->visit($ctx->e());
        return 'rune';
    }

    public function visitLenExpr($ctx) {
        $this->visit($ctx->e());
        return 'int32';
    }

    public function visitNowExpr($ctx) {
        return 'int32';
    }

    public function visitSubstrExpr($ctx) {
        foreach ($ctx->e() as $exprCtx) {
            $this->visit($exprCtx);
        }
        return 'string';
    }

    public function visitTypeOfExpr($ctx) {
        $this->visit($ctx->e());
        return 'string';
    }

    // --- BLOQUES ---
    public function visitB($ctx) {
        // Scope anidado para if, for, etc.
        $this->symbolTable->pushScope('block_' . uniqid());
        foreach ($ctx->stmt() as $stmt) {
            $this->visit($stmt);
        }
        $this->symbolTable->popScope();
        return null;
    }

    /**
     * Utilidad para registrar errores semánticos con la línea y columna exacta.
     */
    private function addError(string $message, $ctx) {
        $line = $ctx->start->getLine();
        $col = $ctx->start->getCharPositionInLine();
        $this->errors[] = [
            'type' => 'Semántico',
            'desc' => $message,
            'line' => $line,
            'col'  => $col
        ];
    }

    // CONTROL DE FLUJO (Validaciones)
    public function visitIfStmt($ctx) {
        $condType = $this->visit($ctx->e());
        
        if ($condType !== 'unknown' && $condType !== 'bool') {
            $this->addError("La condición de una sentencia 'if' debe ser de tipo 'bool', se recibió '$condType'.", $ctx);
        }

        $this->visit($ctx->block(0));
        if ($ctx->block(1) !== null) {
            $this->visit($ctx->block(1)); // Visitar else
        }
        return null;
    }

    public function visitIfElseIfStmt($ctx) {
        $condType = $this->visit($ctx->e());
        
        if ($condType !== 'unknown' && $condType !== 'bool') {
            $this->addError("La condición de una sentencia 'if' debe ser de tipo 'bool', se recibió '$condType'.", $ctx);
        }

        $this->visit($ctx->block());
        $this->visit($ctx->stmt()); // Visitar el else if encadenado
        return null;
    }

    public function visitReturnStmt($ctx) {
        $exprs = $ctx->e();
        $actualTypes = [];

        foreach ($exprs as $exprCtx) {
            $exprType = $this->visit($exprCtx);
            if ($this->isMultiType($exprType)) {
                foreach ($exprType as $type) {
                    $actualTypes[] = $type;
                }
            } else {
                $actualTypes[] = $exprType;
            }
        }

        if (count($actualTypes) !== count($this->currentFunctionReturns)) {
            $this->addError("Return inválido: se esperaban " . count($this->currentFunctionReturns) . " valor(es) y se recibieron " . count($actualTypes) . ".", $ctx);
            return null;
        }

        foreach ($actualTypes as $i => $actualType) {
            $expectedType = $this->currentFunctionReturns[$i] ?? null;
            
            if ($expectedType !== null && $actualType !== 'unknown') {
                // 1. Verificamos con tu TypeChecker normal
                $isValid = $this->typeChecker->checkAssignment($expectedType, $actualType);
                
                // 2. MAGIA: Si falla, verificamos si son "hermanos" de la familia int
                if (!$isValid) {
                    $isIntFamily = ($expectedType === 'int' || $expectedType === 'int32') && 
                                   ($actualType === 'int' || $actualType === 'int32');
                    if ($isIntFamily) {
                        $isValid = true; // Lo perdonamos y lo damos por válido
                    }
                }

                // 3. Si definitivamente no es válido, lanzamos el error
                if (!$isValid) {
                    $this->addError("Return inválido en valor " . ($i + 1) . ": se esperaba '{$expectedType}' y se recibió '{$actualType}'.", $ctx);
                }
            }
        }

        return null;
    }

    public function visitForClassicStmt($ctx) {
        // Scope propio del for para que variables de init 
        // existan en condición, post y cuerpo, sin salir del bloque.
        $this->symbolTable->pushScope('for_' . uniqid());

        $this->visit($ctx->varForInit());

        $condType = $this->visit($ctx->e());
        if ($condType !== 'unknown' && $condType !== 'bool') {
            $this->addError("La condición del 'for' clásico debe ser de tipo 'bool', se recibió '$condType'.", $ctx);
        }

        $this->visit($ctx->block());
        $this->visit($ctx->forPost());

        $this->symbolTable->popScope();
        return null;
    }

    public function visitForVarInit($ctx) {
        $name = $ctx->ID()->getText();
        $type = $ctx->type_()->getText();

        try {
            $rightType = $this->typeForSingleValue($this->visit($ctx->e()), $ctx);
            if ($rightType !== 'unknown' && !$this->typeChecker->checkAssignment($type, $rightType)) {
                $this->addError("No se puede inicializar '{$name}' de tipo '{$type}' con un valor de tipo '{$rightType}'.", $ctx);
            }
            $this->symbolTable->getCurrentScope()->addSymbol($name, $type);
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), $ctx);
        }
        return null;
    }

    public function visitForShortInit($ctx) {
        $name = $ctx->ID()->getText();
        try {
            $rightType = $this->typeForSingleValue($this->visit($ctx->e()), $ctx);
            if ($rightType === 'unknown') {
                $this->addError("No se puede inferir el tipo para la variable '{$name}' en el init del for.", $ctx);
                return null;
            }
            $this->symbolTable->getCurrentScope()->addSymbol($name, $rightType);
        } catch (\Exception $e) {
            $this->addError($e->getMessage(), $ctx);
        }
        return null;
    }

    public function visitForAssignPost($ctx) {
        return $this->visitAssignStmt($ctx);
    }

    public function visitTernaryExpr($ctx) {
        
        $condType = $this->visit($ctx->e(0));
        if (is_array($condType)) $condType = $condType[0]; 
        
        if ($condType !== 'bool' && $condType !== 'unknown') {
            $this->addError("La condición del operador ternario debe ser 'bool', se encontró '{$condType}'.", $ctx);
        }

  
        $type1 = $this->visit($ctx->e(1));
        if (is_array($type1)) $type1 = $type1[0];

        $type2 = $this->visit($ctx->e(2));
        if (is_array($type2)) $type2 = $type2[0];

        if ($type1 !== 'unknown' && $type2 !== 'unknown' && $type1 !== $type2) {
            $this->addError("Los tipos del operador ternario no coinciden: '{$type1}' y '{$type2}'.", $ctx);
            return 'unknown';
        }

        return $type1;
    }
}
