<?php

namespace app\command;

use app\service\AcmeService;
use app\service\CertService;
use app\service\DnsService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class CertProcess extends Command
{
    private $lockHandle = null;

    protected function configure(): void
    {
        $this->setName('cert:process')
            ->setDescription('Process cert orders (DNS issue/renew/install).');
    }

    protected function execute(Input $input, Output $output): int
    {
        if (!$this->acquireProcessLock()) {
            $output->writeln('cert:process skipped. another process is running.');
            return 0;
        }

        try {
            $service = new CertService(new AcmeService(), new DnsService());
            $result = $service->processCertTasks();

            $output->writeln(sprintf(
                'cert:process done. dns=%d issue=%d install=%d',
                (int) ($result['dns']['processed'] ?? 0),
                (int) ($result['issue']['processed'] ?? 0),
                (int) ($result['install']['processed'] ?? 0)
            ));
        } finally {
            $this->releaseProcessLock();
        }

        return 0;
    }

    private function acquireProcessLock(): bool
    {
        $lockDir = runtime_path();
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            return false;
        }

        $lockFile = rtrim($lockDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'cert_process.lock';
        $handle = @fopen($lockFile, 'c+');
        if (!$handle) {
            return false;
        }

        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }

        $this->lockHandle = $handle;
        @ftruncate($this->lockHandle, 0);
        @fwrite($this->lockHandle, (string) getmypid());

        return true;
    }

    private function releaseProcessLock(): void
    {
        if (!is_resource($this->lockHandle)) {
            return;
        }

        @flock($this->lockHandle, LOCK_UN);
        @fclose($this->lockHandle);
        $this->lockHandle = null;
    }
}
