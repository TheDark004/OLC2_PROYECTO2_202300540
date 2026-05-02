<?php
// Archivos generados por ANTLR
require_once __DIR__ . '/src/GolampiVisitor.php';
require_once __DIR__ . '/src/GolampiLexer.php';
require_once __DIR__ . '/src/GolampiParser.php';
require_once __DIR__ . '/src/GolampiBaseVisitor.php';

// Analisis semantico
require_once __DIR__ . '/compiler/semantic/SymbolTable.php';
require_once __DIR__ . '/compiler/semantic/TypeChecker.php';
require_once __DIR__ . '/compiler/semantic/SemanticVisitor.php';

// Generacion ARM64
require_once __DIR__ . '/compiler/codegen/LabelManager.php';
require_once __DIR__ . '/compiler/codegen/RegisterAllocator.php';
require_once __DIR__ . '/compiler/codegen/AsmWriter.php';
require_once __DIR__ . '/compiler/codegen/CodeGenProgramTrait.php';
require_once __DIR__ . '/compiler/codegen/CodeGenAssignmentsTrait.php';
require_once __DIR__ . '/compiler/codegen/CodeGenControlFlowTrait.php';
require_once __DIR__ . '/compiler/codegen/CodeGenPrintTrait.php';
require_once __DIR__ . '/compiler/codegen/CodeGenExprTrait.php';
require_once __DIR__ . '/compiler/codegen/CodeGenFunctionsTrait.php';
require_once __DIR__ . '/compiler/codegen/CodeGen.php';
require_once __DIR__ . '/compiler/codegen/FloatRegisterAllocator.php';

require_once __DIR__ . '/compiler/pipeline/QemuRunner.php';
require_once __DIR__ . '/compiler/pipeline/Compiler.php';