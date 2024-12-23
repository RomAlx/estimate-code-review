<?php
declare(strict_types=1);

namespace Estimator\Command;

use Github\Client;
use Phpml\ModelManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class EstimateCommitCommand extends Command
{
    protected function configure()
    {
        $this->setName('estimate:commit')
            ->setDescription('Estimate cost of a single commit')
            ->addArgument('repo', InputArgument::REQUIRED, 'github repository, example: author/repo')
            ->addArgument('hash', InputArgument::REQUIRED, 'commit hash')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if(!file_exists(__DIR__.'/../../data/model.dat')) {
            $output->writeln('<error>Model not found. Did you train it? (use train command first)</error>');
            return 1;
        }

        [$author, $repo] = explode('/', $input->getArgument('repo'));
        $hash = $input->getArgument('hash');

        $client = new Client();

        $token = $_ENV['REPO_TOKEN'] ?? null;
        if (!$token) {
            $output->writeln('<error>GitHub token not found. Please add REPO_TOKEN to .env file</error>');
            return 1;
        }

        $client->authenticate($token, null, Client::AUTH_ACCESS_TOKEN);

        try {
            // Проверяем доступ к репозиторию
            $output->writeln('Checking repository access...');
            $repoInfo = $client->api('repo')->show($author, $repo);
            $output->writeln(sprintf('Repository found: %s', $repoInfo['full_name']));

            // Получаем информацию о коммите
            $output->writeln(sprintf('Fetching commit %s...', $hash));
            $detail = $client->api('repo')->commits()->show($author, $repo, $hash);

            $stats = $detail['stats'];
            $files = count($detail['files']);

            // Прогнозируем стоимость
            $modelManager = new ModelManager();
            $estimator = $modelManager->restoreFromFile(__DIR__.'/../../data/model.dat');

            $prediction = $estimator->predict([[
                1,
                $stats['additions'],
                $stats['deletions'],
                $files,
                0,
                0
            ]]);

            $cost = round($prediction[0], 2);

            // Выводим результат
            $output->writeln('');
            $output->writeln('COMMIT DETAILS:');
            $output->writeln(sprintf('SHA: <info>%s</info>', $detail['sha']));
            $output->writeln(sprintf('Author: <info>%s</info>', $detail['commit']['author']['name']));
            $output->writeln(sprintf('Date: <info>%s</info>', $detail['commit']['author']['date']));
            $output->writeln(sprintf('Message: <info>%s</info>', $detail['commit']['message']));
            $output->writeln('');
            $output->writeln('STATISTICS:');
            $output->writeln(sprintf('Lines added: <info>%d</info>', $stats['additions']));
            $output->writeln(sprintf('Lines deleted: <info>%d</info>', $stats['deletions']));
            $output->writeln(sprintf('Files changed: <info>%d</info>', $files));
            $output->writeln(sprintf('Estimated cost: <info>$%.2f</info>', $cost));

            return 0;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Error: %s</error>', $e->getMessage()));
            if ($output->isVerbose()) {
                $output->writeln('Stack trace:');
                $output->writeln($e->getTraceAsString());
            }
            return 1;
        }
    }
}