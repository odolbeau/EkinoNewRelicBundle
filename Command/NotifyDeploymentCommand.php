<?php

/*
 * This file is part of Ekino New Relic bundle.
 *
 * (c) Ekino - Thomas Rabaix <thomas.rabaix@ekino.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ekino\Bundle\NewRelicBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

use Ekino\Bundle\NewRelicBundle\NewRelic\NewRelic;

class NotifyDeploymentCommand extends Command
{
    /**
     * @var NewRelic
     */
    private $newrelic;

    /**
     * @param NewRelic $newrelic
     */
    public function __construct(NewRelic $newrelic)
    {
        $this->newrelic = $newrelic;

        parent::__construct();
    }


    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('newrelic:notify-deployment')
            ->setDefinition(array(
                new InputOption(
                    'user', null, InputOption::VALUE_OPTIONAL,
                    'The name of the user/process that triggered this deployment', null
                ),
                new InputOption(
                    'revision', null, InputOption::VALUE_OPTIONAL,
                    'A revision number (e.g., git commit SHA)', null
                ),
                new InputOption(
                    'changelog', null, InputOption::VALUE_OPTIONAL,
                    'A list of changes for this deployment', null
                ),
                new InputOption(
                    'description', null, InputOption::VALUE_OPTIONAL,
                    'Text annotation for the deployment — notes for you', null
                ),
            ))
            ->setDescription('Notifies New Relic that a new deployment has been made')
        ;
    }

    /**
     * {@inheritDoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $newrelic = $this->newrelic;

        $response = $this->performRequest($newrelic->getApiKey(), $this->createPayload($newrelic, $input));

        if (empty($response)) {
            $output->writeLn("<error>Deployment not recorded: Did not understand response</error>");
        } elseif (!in_array($response['status'], array(200, 201))) {
            $output->writeLn(sprintf('<error>Deployment not recorded. Received "%d - %s"</error>', $response['status'], $response['error']));
        } else {
            $output->writeLn(sprintf('<info>Recorded deployment to "%s" (%s)</info>', $newrelic->getName(), ($input->getOption('description') ? $input->getOption('description') : date('r'))));
        }
    }

    public function performRequest($api_key, $payload)
    {
        $headers = array(
            sprintf('x-api-key: %s', $api_key),
            'Content-type: application/x-www-form-urlencoded'
        );

        $context = array(
            'http' => array(
                'method'           => 'POST',
                'header'           => implode("\r\n", $headers),
                'content'          => $payload,
                'ignore_errors'    => true,
            )
        );

        $level = error_reporting(0);
        $content = file_get_contents('https://api.newrelic.com/deployments.xml', 0, stream_context_create($context));
        error_reporting($level);
        if (false === $content) {
            $error = error_get_last();
            throw new \RuntimeException($error['message']);
        }

        $response = array(
            'status' => null,
            'error' => null,
        );

        if (isset($http_response_header[0])) {
            preg_match('/^HTTP\/1.\d (\d+)/', $http_response_header[0], $matches);

            if (isset($matches[1])) {
                $status = $matches[1];

                $response['status'] = $status;

                preg_match('/<error>(.*?)<\/error>/', $content, $matches);

                if (isset($matches[1])) {
                    $response['error'] = $matches[1];
                }
            }
        }

        return $response;
    }

    protected function createPayload(NewRelic $newrelic, InputInterface $input)
    {
        $content_array = array(
            'deployment[app_name]' => $newrelic->getName()
        );

        if (($user = $input->getOption('user')))
        {
            $content_array['deployment[user]'] = $user;
        }

        if (($revision = $input->getOption('revision')))
        {
            $content_array['deployment[revision]'] = $revision;
        }

        if (($changelog = $input->getOption('changelog')))
        {
            $content_array['deployment[changelog]'] = $changelog;
        }

        if (($description = $input->getOption('description')))
        {
            $content_array['deployment[description]'] = $description;
        }

        return http_build_query($content_array);
    }
}
