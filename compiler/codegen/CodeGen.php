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
    private SymbolTable $symbolTable;

    public function __construct(SymbolTable $st) {
        $this->asm       = new AsmWriter();
        $this->labels    = new LabelManager();
        $this->regs      = new RegisterAllocator();
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
            if ($symbols[$i]->name === $name) {
                return $symbols[$i];
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
