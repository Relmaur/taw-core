<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retired with taw-hub (2026-10-08). It registered the site with a TAW Hub's enrolment endpoint. Kept as a stub so a theme whose
 * bin/taw still registers it keeps working; it only explains what replaced it.
 * Remove the registration from bin/taw (`php bin/taw sync`), then this class
 * can go in a major release.
 *
 * What replaced it: the companion ships with each TAW theme as an mu-plugin
 * (composer `taw/hub-companion` + its `mu-loader/`), and taw-fleet reads the
 * sites directly (`taw-fleet live`).
 */
class HubEnrollCommand extends Command
{
    // Themes still call `new …($themeDir)`; PHP ignores the extra argument.
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hub:enroll')
            ->setDescription('Retired with taw-hub: the companion ships with the theme; taw-fleet reads the sites')
            ->setHidden(true)
            ->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        (new SymfonyStyle($input, $output))->warning([
            '`hub:enroll` was retired with taw-hub (2026-10-08).',
            'The companion ships with the theme as an mu-plugin: require taw/hub-companion in composer.json and copy its mu-loader/taw-companion.php to wp-content/mu-plugins/ (the theme\'s deploy does this).',
            'taw-fleet reads the site through it: taw-fleet live.',
        ]);

        return Command::FAILURE;
    }
}
