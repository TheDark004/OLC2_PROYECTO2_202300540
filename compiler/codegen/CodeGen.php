<?php

class CodeGen extends GolampiBaseVisitor {
    use CodeGenProgramTrait;
    use CodeGenAssignmentsTrait;
    use CodeGenControlFlowTrait;
    use CodeGenPrintTrait;
    use CodeGenExprTrait;
    use CodeGenFunctionsTrait;


    private AsmWriter $asm;
    private LabelManager $labels;
    private RegisterAllocator $regs;
    private FloatRegisterAllocator $fregs;
    private SymbolTable $symbolTable;
    private array $functionSignatures = [];
    private ?Scope $currentFunctionScope = null;

    public function __construct(SymbolTable $st, array $functionSignatures = []) {
        $this->functionSignatures = $functionSignatures;
        $this->asm       = new AsmWriter();
        $this->labels    = new LabelManager();
        $this->regs      = new RegisterAllocator();
        $this->fregs     = new FloatRegisterAllocator();
        $this->symbolTable = $st;
    }

    public function getOutput(): string {
        return $this->asm->getContent();
    }

    //  BUSQUEDA DE SIMBOLOS
    //  De atrás hacia adelante garantiza que encontremos
    //  la variable más local (scope más cercano).
    private function getSymbol(string $name) {
        $symbols = $this->symbolTable->getAllSymbols();
        for ($i = count($symbols) - 1; $i >= 0; $i--) {
            $symbol = $symbols[$i];
            if ($symbol->name !== $name) {
                continue;
            }

            if ($this->currentFunctionScope === null) {
                if ($symbol->scope === 'global') {
                    return $symbol;
                }
                continue;
            }

            if ($symbol->scopeObj === $this->currentFunctionScope || $symbol->scopeObj->isDescendantOf($this->currentFunctionScope)) {
                return $symbol;
            }

            if ($symbol->scope === 'global') {
                return $symbol;
            }
        }

        return null;
    }
    
    //  -BLOQUE-
    public function visitB($ctx) {
        foreach ($ctx->stmt() as $stmt) {
            $this->visit($stmt);
        }
        return null;
    }
}
