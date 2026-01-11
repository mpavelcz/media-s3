<?php declare(strict_types=1);

namespace MediaS3\Command;

use MediaS3\Service\MediaManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'media:cleanup-failed',
    description: 'Smaže selhané assety starší než zadaný počet hodin'
)]
final class CleanupFailedAssetsCommand extends Command
{
    public function __construct(
        private MediaManager $mediaManager,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'hours',
            'H',
            InputOption::VALUE_OPTIONAL,
            'Smazat assety starší než X hodin',
            24
        );

        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Pouze zobrazí co by se smazalo, nic nemaže'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hours = (int) $input->getOption('hours');
        $dryRun = (bool) $input->getOption('dry-run');

        $output->writeln(sprintf('<info>Hledám selhané assety starší než %d hodin...</info>', $hours));

        $assets = $this->mediaManager->findFailedAssetsOlderThan($this->em, $hours);
        $count = count($assets);

        if ($count === 0) {
            $output->writeln('<comment>Žádné selhané assety k odstranění.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Nalezeno %d selhaných assetů.</info>', $count));

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN - nic nebylo smazáno.</comment>');
            foreach ($assets as $asset) {
                $output->writeln(sprintf('  - ID: %d, vytvořeno: %s, chyba: %s',
                    $asset->getId(),
                    $asset->getCreatedAt()->format('Y-m-d H:i:s'),
                    substr($asset->getLastError() ?? 'N/A', 0, 50)
                ));
            }
            return Command::SUCCESS;
        }

        $deleted = $this->mediaManager->deleteFailedAssetsOlderThan($this->em, $hours);
        $output->writeln(sprintf('<info>Smazáno %d selhaných assetů.</info>', $deleted));

        return Command::SUCCESS;
    }
}
