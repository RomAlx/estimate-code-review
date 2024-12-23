<?php
declare(strict_types=1);

namespace Estimator\Command;

use Github\Client;
use Phpml\ModelManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

final class EstimateRepositoryCommand extends Command
{
    private function writeCSVRow($handle, array $fields)
    {
        fputcsv($handle, $fields, ';');
    }

    private function cleanString(string $str): string
    {
        $str = preg_replace('/[\x00-\x1F\x7F]/u', '', $str);
        $str = str_replace(["\r", "\n"], ' ', $str);
        $str = preg_replace('/\s+/', ' ', $str);
        return trim($str);
    }

    private function formatNumber($number): string
    {
        return number_format($number, 0, ',', ' ');
    }

    private function formatCost($cost): string
    {
        return number_format($cost, 2, ',', ' ') . ' $';
    }

    private function exportToCSV(string $repoName, string $branch, array $commits, array $statistics, string $directory): string
    {
        if (!file_exists($directory)) {
            mkdir($directory, 0777, true);
        }

        // Формируем имя файла
        $timestamp = date('Y-m-d');
        $repoShortName = explode('/', $repoName)[1];
        $filename = sprintf('%s/%s_%s_%s.csv', $directory, $repoShortName, $branch, $timestamp);

        $handle = fopen($filename, 'w');
        fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

        // Заголовок отчета
        $this->writeCSVRow($handle, ['Отчет по анализу репозитория']);
        $this->writeCSVRow($handle, ['']);
        $this->writeCSVRow($handle, ['Репозиторий', $repoName]);
        $this->writeCSVRow($handle, ['Ветка', $branch]);
        $this->writeCSVRow($handle, ['Дата анализа', date('d.m.Y H:i:s')]);
        $this->writeCSVRow($handle, ['']);

        // Заголовки таблицы
        $this->writeCSVRow($handle, [
            '№',
            'Хеш коммита',
            'Описание',
            'Добавлено строк',
            'Удалено строк',
            'Изменено файлов',
            'Стоимость'
        ]);

        // Данные коммитов
        foreach ($commits as $index => $commit) {
            $this->writeCSVRow($handle, [
                $index + 1,
                $commit['sha'],
                $this->cleanString($commit['message']),
                $commit['additions'],
                $commit['deletions'],
                $commit['files'],
                $this->formatCost($commit['cost'])
            ]);
        }

        // Итоги
        $this->writeCSVRow($handle, ['']);
        $this->writeCSVRow($handle, ['ИТОГО']);
        $this->writeCSVRow($handle, ['Всего коммитов', $statistics['total_commits']]);
        $this->writeCSVRow($handle, ['Добавлено строк', $statistics['total_additions']]);
        $this->writeCSVRow($handle, ['Удалено строк', $statistics['total_deletions']]);
        $this->writeCSVRow($handle, ['Изменено файлов', $statistics['total_files']]);
        $this->writeCSVRow($handle, ['Общая стоимость', $this->formatCost($statistics['total_cost'])]);
        $this->writeCSVRow($handle, ['Средняя стоимость', $this->formatCost($statistics['average_cost'])]);

        fclose($handle);
        return $filename;
    }protected function configure()
{
    $this->setName('estimate:repository')
        ->setDescription('Оценка стоимости разработки репозитория')
        ->addArgument('repo', InputArgument::REQUIRED, 'github репозиторий, пример: author/repo')
        ->addOption('branch', 'b', InputOption::VALUE_OPTIONAL, 'Ветка для анализа', 'master')
    ;
}

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Добавляем стили для вывода
        $output->getFormatter()->setStyle('success', new OutputFormatterStyle('green'));
        $output->getFormatter()->setStyle('info', new OutputFormatterStyle('blue'));
        $output->getFormatter()->setStyle('warning', new OutputFormatterStyle('yellow'));
        $output->getFormatter()->setStyle('metric', new OutputFormatterStyle('magenta'));

        if(!file_exists(__DIR__.'/../../data/model.dat')) {
            $output->writeln('<error>Модель не найдена. Сначала обучите модель командой train</error>');
            return 1;
        }

        [$author, $repo] = explode('/', $input->getArgument('repo'));
        $client = new Client();

        $token = $_ENV['REPO_TOKEN'] ?? null;
        if (!$token) {
            $output->writeln('<error>Токен GitHub не найден. Добавьте REPO_TOKEN в файл .env</error>');
            return 1;
        }

        $client->authenticate($token, null, Client::AUTH_ACCESS_TOKEN);

        try {
            $user = $client->api('current_user')->show();
            $output->writeln(sprintf('✓ <success>Подключение к GitHub: успешно (%s)</success>', $user['login']));
        } catch (\Exception $e) {
            $output->writeln('<error>Ошибка аутентификации: ' . $e->getMessage() . '</error>');
            return 1;
        }

        $modelManager = new ModelManager();
        $estimator = $modelManager->restoreFromFile(__DIR__.'/../../data/model.dat');

        $output->writeln(sprintf("\n📊 <info>Анализ репозитория %s</info>\n", $input->getArgument('repo')));

        try {
            // Проверяем доступ к репозиторию
            $repoInfo = $client->api('repo')->show($author, $repo);
            $output->writeln('✓ <success>Проверка доступа к репозиторию: успешно</success>');

            // Получаем список веток
            $branches = $client->api('repo')->branches($author, $repo);
            $output->writeln("\nℹ️  <info>Доступные ветки:</info>");
            $branchNames = [];
            foreach ($branches as $branch) {
                $isDefault = $branch['name'] === $repoInfo['default_branch'];
                $branchNames[] = $branch['name'];
                $output->writeln(sprintf('  • %s%s',
                    $branch['name'],
                    $isDefault ? ' (основная)' : ''
                ));
            }

            // Определяем ветку для анализа
            $branch = $input->getOption('branch');
            if (!in_array($branch, $branchNames)) {
                $output->writeln(sprintf('<warning>Ветка %s не найдена!</warning>', $branch));
                $branch = $repoInfo['default_branch'];
                $output->writeln(sprintf('<info>Используем основную ветку: %s</info>', $branch));
            }

            // Получаем все коммиты с пагинацией
            $output->writeln(sprintf("\n⚡ <info>Начинаем анализ ветки %s</info>", $branch));
            $page = 1;
            $perPage = 100;
            $allCommits = [];

            while (true) {
                $pageCommits = $client->api('repo')->commits()->all($author, $repo, [
                    'sha' => $branch,
                    'page' => $page,
                    'per_page' => $perPage
                ]);

                if (empty($pageCommits)) {
                    break;
                }

                $allCommits = array_merge($allCommits, $pageCommits);
                $page++;
            }

            if (empty($allCommits)) {
                $output->writeln('<error>Коммиты не найдены</error>');
                return 1;
            }

            $commitsData = [];
            $totalAdditions = 0;
            $totalDeletions = 0;
            $totalFiles = 0;
            $commitCount = 0;
            $total = 0;foreach($allCommits as $index => $commit) {
                $detail = $client->api('repo')->commits()->show($author, $repo, $commit['sha']);
                $stats = $detail['stats'];
                $files = count($detail['files']);

                $prediction = $estimator->predict([[
                    1,
                    $stats['additions'],
                    $stats['deletions'],
                    $files,
                    0,
                    0
                ]]);

                $cost = round($prediction[0], 2);
                $total += $cost;
                $commitCount++;

                $totalAdditions += $stats['additions'];
                $totalDeletions += $stats['deletions'];
                $totalFiles += $files;

                $shortMessage = substr($commit['commit']['message'], 0, 50);
                if (strlen($commit['commit']['message']) > 50) {
                    $shortMessage .= '...';
                }

                // Сохраняем данные для CSV
                $commitsData[] = [
                    'sha' => substr($commit['sha'], 0, 8),
                    'message' => $shortMessage,
                    'additions' => $stats['additions'],
                    'deletions' => $stats['deletions'],
                    'files' => $files,
                    'cost' => $cost
                ];

                // Выводим прогресс
                $output->writeln(sprintf(
                    '[%d/%d] <info>%s</info> %s | +%d -%d | %d %s | %s',
                    $index + 1,
                    count($allCommits),
                    substr($commit['sha'], 0, 8),
                    $this->cleanString($shortMessage),
                    $stats['additions'],
                    $stats['deletions'],
                    $files,
                    $this->getFilesWord($files),
                    $this->formatCost($cost)
                ));
            }

            $statistics = [
                'total_commits' => $commitCount,
                'total_additions' => $totalAdditions,
                'total_deletions' => $totalDeletions,
                'total_files' => $totalFiles,
                'total_cost' => $total,
                'average_cost' => $total / $commitCount
            ];

            $reportDir = __DIR__ . '/../../data/' . $repo;
            $csvFile = $this->exportToCSV(
                $input->getArgument('repo'),
                $branch,
                $commitsData,
                $statistics,
                $reportDir
            );

            // Выводим красивую статистику
            $output->writeln("\n📊 <info>Итоговая статистика:</info>");
            $output->writeln('╔════════════════════════╤══════════════╗');
            $output->writeln(sprintf('║ Всего коммитов        │ %-12s ║', $this->formatNumber($commitCount)));
            $output->writeln(sprintf('║ Добавлено строк       │ %-12s ║', $this->formatNumber($totalAdditions)));
            $output->writeln(sprintf('║ Удалено строк         │ %-12s ║', $this->formatNumber($totalDeletions)));
            $output->writeln(sprintf('║ Изменено файлов       │ %-12s ║', $this->formatNumber($totalFiles)));
            $output->writeln(sprintf('║ Общая стоимость       │ %-12s ║', $this->formatCost($total)));
            $output->writeln(sprintf('║ Средняя цена коммита  │ %-12s ║', $this->formatCost($total / $commitCount)));
            $output->writeln('╚════════════════════════╧══════════════╝');

            $output->writeln(sprintf("\n💾 <success>Отчёт сохранен: %s</success>", $csvFile));

            return 0;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Ошибка: %s</error>', $e->getMessage()));
            if ($output->isVerbose()) {
                $output->writeln('Стек вызовов:');
                $output->writeln($e->getTraceAsString());
            }
            return 1;
        }
    }

    private function getFilesWord(int $count): string
    {
        if ($count % 10 == 1 && $count % 100 != 11) {
            return 'файл';
        } else if ($count % 10 >= 2 && $count % 10 <= 4 && ($count % 100 < 10 || $count % 100 >= 20)) {
            return 'файла';
        } else {
            return 'файлов';
        }
    }
}