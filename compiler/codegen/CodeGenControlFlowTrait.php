<?php

trait CodeGenControlFlowTrait {
    // Contexto de control para break/continue.
    // Cada entrada: ['kind' => 'loop'|'switch', 'break' => 'LBL', 'continue' => 'LBL|null']
    private array $controlFlowStack = [];

    private function pushLoopContext(string $breakLabel, ?string $continueLabel): void {
        $this->controlFlowStack[] = [
            'kind' => 'loop',
            'break' => $breakLabel,
            'continue' => $continueLabel,
        ];
    }

    private function pushSwitchContext(string $breakLabel): void {
        $this->controlFlowStack[] = [
            'kind' => 'switch',
            'break' => $breakLabel,
            'continue' => null,
        ];
    }

    private function popControlFlowContext(): void {
        array_pop($this->controlFlowStack);
    }

    private function getNearestBreakLabel(): ?string {
        for ($i = count($this->controlFlowStack) - 1; $i >= 0; $i--) {
            if (isset($this->controlFlowStack[$i]['break'])) {
                return $this->controlFlowStack[$i]['break'];
            }
        }
        return null;
    }

    private function getNearestContinueLabel(): ?string {
        for ($i = count($this->controlFlowStack) - 1; $i >= 0; $i--) {
            if (($this->controlFlowStack[$i]['kind'] ?? null) === 'loop') {
                return $this->controlFlowStack[$i]['continue'];
            }
        }
        return null;
    }

    private function isSwitchCaseEntry($caseCtx): bool {
        return method_exists($caseCtx, 'e') && $caseCtx->e() !== null;
    }

    // - CONTROL DE FLUJO -
    public function visitIfStmt($ctx) {
        $hasElse  = $ctx->block(1) !== null;
        $elseLabel = $this->labels->newLabel("ELSE");
        $endLabel  = $this->labels->newLabel("ENDIF");

        $this->asm->writeComment("--- IF ---");
        $condReg = $this->visit($ctx->e());
        $wCond   = $this->regs->to32($condReg);
        $this->asm->writeLine("cmp $wCond, #0");
        $this->regs->free($condReg);

        $this->asm->writeLine($hasElse ? "b.eq $elseLabel" : "b.eq $endLabel");

        $this->visit($ctx->block(0));

        if ($hasElse) {
            $this->asm->writeLine("b $endLabel");
            $this->asm->writeLabel($elseLabel);
            $this->visit($ctx->block(1));
        }

        $this->asm->writeLabel($endLabel);
        return null;
    }

    public function visitIfElseIfStmt($ctx) {
        $endLabel  = $this->labels->newLabel("ENDIF_C");
        $elseLabel = $this->labels->newLabel("ELSE_IF");

        $condReg = $this->visit($ctx->e());
        $wCond   = $this->regs->to32($condReg);
        $this->asm->writeLine("cmp $wCond, #0");
        $this->regs->free($condReg);
        $this->asm->writeLine("b.eq $elseLabel");

        $this->visit($ctx->block());
        $this->asm->writeLine("b $endLabel");
        $this->asm->writeLabel($elseLabel);
        $this->visit($ctx->stmt());
        $this->asm->writeLabel($endLabel);
        return null;
    }

    // FOR condicional (tipo while): for e { block }
    public function visitForWhileStmt($ctx) {
        $startLabel = $this->labels->newLabel("FOR_S");
        $endLabel   = $this->labels->newLabel("FOR_E");
        $postLabel  = $this->labels->newLabel("FOR_POST");

        $this->asm->writeComment("- FOR while -");
        $this->pushLoopContext($endLabel, $postLabel);
        $this->asm->writeLabel($startLabel);

        $condReg = $this->visit($ctx->e());
        $wCond   = $this->regs->to32($condReg);
        $this->asm->writeLine("cmp $wCond, #0");
        $this->regs->free($condReg);
        $this->asm->writeLine("b.eq $endLabel");

        $this->visit($ctx->block());
        $this->asm->writeLabel($postLabel);
        $this->asm->writeLine("b $startLabel");
        $this->asm->writeLabel($endLabel);
        $this->popControlFlowContext();
        return null;
    }

    /**
     * FOR infinito: for { block }
     * Solo se sale con break.
     * FOR_START:
     */
    public function visitForInfiniteStmt($ctx) {
        $startLabel = $this->labels->newLabel("FOR_INF");
        $endLabel   = $this->labels->newLabel("FOR_INF_END");
        $postLabel  = $this->labels->newLabel("FOR_INF_POST");

        $this->asm->writeComment("- FOR infinito -");
        $this->pushLoopContext($endLabel, $postLabel);
        $this->asm->writeLabel($startLabel);
        $this->visit($ctx->block());
        $this->asm->writeLabel($postLabel);
        $this->asm->writeLine("b $startLabel");
        $this->asm->writeLabel($endLabel);
        $this->popControlFlowContext();
        return null;
    }

    public function visitForClassicStmt($ctx) {
        $condLabel = $this->labels->newLabel("FOR_C_COND");
        $postLabel = $this->labels->newLabel("FOR_C_POST");
        $endLabel  = $this->labels->newLabel("FOR_C_END");

        $this->asm->writeComment("- FOR clasico -");
        $this->visit($ctx->varForInit());

        $this->pushLoopContext($endLabel, $postLabel);
        $this->asm->writeLabel($condLabel);

        $condReg = $this->visit($ctx->e());
        $wCond = $this->regs->to32($condReg);
        $this->asm->writeLine("cmp $wCond, #0");
        $this->regs->free($condReg);
        $this->asm->writeLine("b.eq $endLabel");

        $this->visit($ctx->block());
        $this->asm->writeLabel($postLabel);
        $this->visit($ctx->forPost());
        $this->asm->writeLine("b $condLabel");
        $this->asm->writeLabel($endLabel);
        $this->popControlFlowContext();
        return null;
    }

    public function visitSwitchStmt($ctx) {
        $endLabel = $this->labels->newLabel("SW_END");
        $dispatchLabel = $this->labels->newLabel("SW_DISPATCH");
        $defaultLabel = $endLabel;

        $switchExprReg = null;
        $switchExprW = null;
        if ($ctx->e() !== null) {
            $switchExprReg = $this->visit($ctx->e());
            $switchExprW = $this->regs->to32($switchExprReg);
        }

        $cases = $ctx->switchCase();
        $caseEntries = [];
        foreach ($cases as $caseCtx) {
            if ($this->isSwitchCaseEntry($caseCtx)) {
                $caseEntries[] = [
                    'ctx' => $caseCtx,
                    'bodyLabel' => $this->labels->newLabel("SW_CASE"),
                    'nextLabel' => $this->labels->newLabel("SW_NEXT"),
                ];
                continue;
            }

            $caseEntries[] = [
                'ctx' => $caseCtx,
                'bodyLabel' => null,
                'nextLabel' => null,
            ];
            $defaultLabel = $this->labels->newLabel("SW_DEFAULT");
        }

        $this->pushSwitchContext($endLabel);
        $this->asm->writeLine("b $dispatchLabel");

        foreach ($caseEntries as $entry) {
            $caseCtx = $entry['ctx'];
            if ($this->isSwitchCaseEntry($caseCtx)) {
                $this->asm->writeLabel($entry['bodyLabel']);
                foreach ($caseCtx->stmt() as $stmt) {
                    $this->visit($stmt);
                }
                $this->asm->writeLine("b $endLabel");
            } else {
                $this->asm->writeLabel($defaultLabel);
                foreach ($caseCtx->stmt() as $stmt) {
                    $this->visit($stmt);
                }
                $this->asm->writeLine("b $endLabel");
            }
        }

        $this->asm->writeLabel($dispatchLabel);
        foreach ($caseEntries as $entry) {
            $caseCtx = $entry['ctx'];
            if (!$this->isSwitchCaseEntry($caseCtx)) continue;

            $caseExprReg = $this->visit($caseCtx->e());
            $caseExprW = $this->regs->to32($caseExprReg);
            if ($switchExprReg !== null) {
                $this->asm->writeLine("cmp $switchExprW, $caseExprW");
            } else {
                $this->asm->writeLine("cmp $caseExprW, #0");
            }
            $this->asm->writeLine("b.ne {$entry['nextLabel']}");
            $this->asm->writeLine("b {$entry['bodyLabel']}");
            $this->asm->writeLabel($entry['nextLabel']);
            $this->regs->free($caseExprReg);
        }

        $this->asm->writeLine("b $defaultLabel");
        $this->asm->writeLabel($endLabel);
        $this->popControlFlowContext();

        if ($switchExprReg !== null) {
            $this->regs->free($switchExprReg);
        }
        return null;
    }

    public function visitBreakStmt($ctx) {
        $breakLabel = $this->getNearestBreakLabel();
        if ($breakLabel !== null) {
            $this->asm->writeLine("b $breakLabel");
        } else {
            $this->asm->writeComment("break fuera de loop/switch (ignorado)");
        }
        return null;
    }

    public function visitContinueStmt($ctx) {
        $continueLabel = $this->getNearestContinueLabel();
        if ($continueLabel !== null) {
            $this->asm->writeLine("b $continueLabel");
        } else {
            $this->asm->writeComment("continue fuera de loop (ignorado)");
        }
        return null;
    }
}
