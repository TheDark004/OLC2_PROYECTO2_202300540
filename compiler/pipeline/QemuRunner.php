<?php

class QemuRunner {
    private string $tempDir;
    private string $asmFile;
    private string $objFile;
    private string $exeFile;

    public function __construct() {
        // Carpeta temporal para los archivos del proceso
        $this->tempDir = sys_get_temp_dir() . '/golampi_' . uniqid();
        mkdir($this->tempDir);
        
        $this->asmFile = $this->tempDir . '/program.s';
        $this->objFile = $this->tempDir . '/program.o';
        $this->exeFile = $this->tempDir . '/program';
    }

    /**
     * Toma el código ensamblador, lo compila y lo corre en QEMU.
     */
    public function run(string $asmCode): array {
        // 1. Guardar el código generado en un archivo .s
        file_put_contents($this->asmFile, $asmCode);

        try {
            // Ensamblar (AArch64)
            $this->execute("aarch64-linux-gnu-as -o {$this->objFile} {$this->asmFile}");

            // Linkear (Generar el ejecutable)
            $this->execute("aarch64-linux-gnu-ld -o {$this->exeFile} {$this->objFile}");

            // Ejecutar en QEMU y capturar la salida
            $output = [];
            $returnCode = 0;
            exec("qemu-aarch64 {$this->exeFile} 2>&1", $output, $returnCode);

            return [
                'success' => true,
                'output'  => implode("\n", $output),
                'exitCode' => $returnCode
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        } finally {
            // Limpieza (opcional: puedes comentar esto si quieres ver los archivos generados)
            $this->cleanup();
        }
    }

    private function execute(string $command): void {
        $output = [];
        $returnCode = 0;
        exec($command . " 2>&1", $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new Exception("Error en comando [$command]: " . implode("\n", $output));
        }
    }

    private function cleanup(): void {
        if (file_exists($this->asmFile)) unlink($this->asmFile);
        if (file_exists($this->objFile)) unlink($this->objFile);
        if (file_exists($this->exeFile)) unlink($this->exeFile);
        if (is_dir($this->tempDir)) rmdir($this->tempDir);
    }
}