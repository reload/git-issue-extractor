<?php
/**
 * @file
 * Extracts info about jira-issues mentioned in commits.
 */

namespace DR\Drupal\GitIssueExtractor;

use JiraClient\JiraClient;
use JiraClient\Resource\AbstractResource;
use JiraClient\Resource\Project;
use JiraClient\Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ExtractIssuesCommand extends Command
{
    protected $jiraEndpoint;

    /**
     * Injected logger.
     * @var LoggerInterface
     */
    private $logger;

    /**
     * Injected Jira client.
     * @var JiraClient
     */
    private $jira;

    /**
     * Command constructor.
     *
     * Sets up static configuration that is independent of user-provided arguments
     * and options.
     */
    public function __construct(LoggerInterface $logger, JiraClient $jira, $jiraEndpoint)
    {
        $this->jiraEndpoint = $jiraEndpoint;
        $this->jira = $jira;

        $this->logger = $logger;

        parent::__construct();
    }

    /**
     * Command configuration.
     */
    protected function configure()
    {
        $this->setName('extract');
        $this->setDescription('Extracts issues');
        $this->addArgument(
            'start',
            InputArgument::REQUIRED,
            'a sha, tag or branch'
        );
        $this->addArgument(
            'end',
            InputArgument::REQUIRED,
            'a sha, tag or branch'
        );
        $this->addOption(
            'no-merges',
            null,
            InputOption::VALUE_NONE,
            'Whether to ignore merge-commits when looking for issues. This requires developers to add issue-numbers to their commit-messages.'
        );
        $this->addOption(
            'release-note',
            null,
            InputOption::VALUE_NONE,
            'Whether or not to format the output to suit release notes.'
        );
    }

    /**
     * Executes the command.
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger->info("Collecting project-keys from Jira");
        /** @var Response $response */
        $response = $this->jira->callGet('/project');
        $projects_raw = $response->getData();
        if (empty($projects_raw)) {
            $this->logger->error("Could not retrive project-list");

            return false;
        }
        $projects = AbstractResource::deserializeArrayValue('project', $projects_raw, $this->jira);
        $this->logger->info("Got {projects} projects",
            ['projects' => count($projects)]);
        $project_keys = array_map(function (Project $project) {
            return $project->getKey();
        }, $projects);

        // Process our arguments and options.
        $start = $input->getArgument('start');
        $end = $input->getArgument('end');
        $this->logger->info(
            'Extract issues between {start} and {end}',
            [
                'start' => $start,
                'end' => $end,
            ]
        );

        if ($input->getOption('no-merges')) {
            $this->logger->info('- skipping merges');
            $nomerge = ' --no-merges';
        } else {
            $nomerge = '';
        }

        if ($input->getOption('release-note')) {
            $output_format = 'release_note';
        } else {
            $output_format = 'table';
        }

        // Get the list of commits in the range and find anything that looks like a
        // Jira-issue.
        $git = new Process(
            'git log ' . $nomerge . ' ' . $start . '..' . $end
        );
        $git->mustRun();
        $git_output = $git->getOutput();

        $imploded_keys = implode('|', $project_keys);
        preg_match_all(
            "%(?<issues>($imploded_keys)[- ][0-9]+)%",
            $git_output,
            $matches
        );

        $issues = $matches['issues'];
        $issues = array_unique($issues);
        sort($issues, SORT_NATURAL);

        // Output status.
        $issue_count = count($issues);
        $this->logger->info("Getting status for $issue_count issues");

        if (count($issues)) {
            $progress = new ProgressBar($output, $issue_count);
            $progress->setMessage('');
            $progress->setFormat(
                ' %current%/%max% [%bar%] %message% %percent:3s%% elapsed/estimated: %elapsed:6s%/%estimated:-6s%'
            );

            $progress->start();

            $issues_loaded = [];
            foreach ($issues as $issue_id) {
                try {
                    $progress->setMessage($issue_id);
                    $issue = $this->jira->issue()->get(
                        $issue_id,
                        ['status', 'summary']
                    );
                    $issues_loaded[$issue_id] = $issue;
                } catch (\Exception $e) {
                    $this->logger->error(
                        "Could not load issue {issue_id}",
                        ['issue_id' => $issue_id]
                    );
                }
                $progress->advance();
            }
            // Break away from the progress-bar.
            print ("\n\n");

            switch ($output_format) {
                case 'table':
                    $this->formatTable($issues_loaded, $output);
                    break;

                case 'release_note':
                    $this->formatReleaseNote($issues_loaded, $output);
                    break;
            }
        } else {
            $this->logger->info("No issues found.");
        }
    }

    /**
     * Format list of issues as a table.
     *
     * @param array $issues
     *   Array of issues keyed by the issue id.
     * @param OutputInterface $output
     *   The output-handler.
     */
    private function formatTable(array $issues, OutputInterface $output)
    {
        // Prepare for output.
        $table = new Table($output);
        $table->setHeaders(
            array(
                'Url',
                'Status',
                'Title',
            )
        );

        foreach ($issues as $issue_id => $issue) {
            // Extract data from Jira and add it to the table.
            $table->addRow(
                [
                    $this->jiraEndpoint . '/browse/' . $issue_id,
                    $issue->status->getName(),
                    substr($issue->getSummary(), 0, 50),
                ]
            );
        }

        $table->render();
        // Break away from the table.
        print ("\nIssues: " . implode(', ', array_keys($issues)) . "\n");
    }

    /**
     * Format the list of issues for a release-note.
     *
     * @param array $issues
     *   Array of issues keyed by the issue id.
     * @param OutputInterface $output
     *   The output-handler.
     */
    private function formatReleaseNote(array $issues, OutputInterface $output)
    {
        foreach ($issues as $issue_id => $issue) {
            $output->writeln('- ' . $issue->getSummary());
            $output->writeln(' ' . $this->jiraEndpoint . '/browse/' . $issue_id);
            $output->writeln("");
        }
    }
}
