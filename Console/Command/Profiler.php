<?php

namespace Sparta\CronProfiler\Console\Command;
use Magento\Framework\DB\Select;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Catalog\Model\Category;
use Magento\Framework\Registry;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;

class Profiler extends Command
{
    /**
     * Mode input key value
     */
    const INPUT_OFFSET = 'offset';

    /**
     * Mode input key value
     */
    const INPUT_CODE = 'code';

    /**
     * @var \Symfony\Component\Console\Helper\ProgressBar
     */
    protected $progressBar;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * @var \Magento\Framework\DB\Adapter\Pdo\Mysql
     */
    protected $connection;

    /**
     * @var \Magento\Framework\Module\ModuleResource
     */
    protected $moduleResource;

    /**
     * @var State
     */
    protected $state;

    /**
     * {@inheritdoc}
     */
    public function __construct()
    {
        /** @var Registry $registry */
        $registry = $this->getObjectManager()->get(Registry::class);
        $registry->register('Sparta_CronProfiler', true);
        $this->state = $this->objectManager->get(State::class);
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('sparta:cron:profile')
            ->setDescription('Profile Cron Jobs')
            ->addOption(self::INPUT_OFFSET, 'o', InputOption::VALUE_OPTIONAL,
                'Offset')
            ->addOption(self::INPUT_CODE, 'c', InputOption::VALUE_OPTIONAL,
                'job_code');
        parent::configure();
    }

    /**
     * Returns formatted time
     *
     * @param $time
     * @return string
     */
    protected function formatTime($time)
    {
        return sprintf('%02d:%02d:%02d', ($time / 3600), ($time / 60 % 60), $time % 60);
    }

    /**
     * Returns abstract resource
     *
     * @return \Magento\Framework\Module\ModuleResource|mixed
     */
    protected function getModuleResource()
    {
        if ($this->moduleResource == null) {
            /** @var \Magento\Framework\Module\ModuleResource $moduleResource */
            $this->moduleResource = $this->getObjectManager()->get('Magento\Framework\Module\ModuleResource');
        }
        return $this->moduleResource;
    }

    /**
     * Returns connection
     *
     * @return false|\Magento\Framework\DB\Adapter\AdapterInterface|\Magento\Framework\DB\Adapter\Pdo\Mysql
     */
    protected function getConnection()
    {
        if ($this->connection == null) {
            $this->connection = $this->getModuleResource()->getConnection();
        }

        return $this->connection;
    }

    /**
     * Setup progress bar
     *
     * @return $this
     */
    private function setupProgress()
    {
        $this->progressBar = new \Symfony\Component\Console\Helper\ProgressBar($this->output);
        $this->progressBar->setFormat('<info>%message%</info> %current%/%max% [%bar%] %percent:3s%%');
        return $this;
    }

    /**
     * Gets initialized object manager
     *
     * @return \Magento\Framework\ObjectManagerInterface
     */
    protected function getObjectManager()
    {
        if (null == $this->objectManager) {
            $this->objectManager = ObjectManager::getInstance();
        }
        return $this->objectManager;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->state->setAreaCode(\Magento\Framework\App\Area::AREA_CRONTAB);
        $this->output = $output;

        $offset = 0;
        if ($input->getOption(self::INPUT_OFFSET)) {
            $offset = (int)$input->getOption(self::INPUT_OFFSET);
        }

        $jobCode = '';
        if ($input->getOption(self::INPUT_CODE)) {
            $jobCode = $input->getOption(self::INPUT_CODE);
        }

        $globalMicroTimeStart = microtime(true);

        /** @var \Magento\Cron\Model\Config\Data $cronConfigData */
        $cronConfigData = $this->objectManager->get(\Magento\Cron\Model\Config\Data::class);
        $cronJobs = $cronConfigData->getJobs();

        $i = 0;

        $errors = [];

        foreach ($cronJobs as $cronGroup) {
            foreach ($cronGroup as $cronJobName => $cronJob) {
                ++$i;

                if (!empty($jobCode) && $jobCode != $cronJobName) {
                    continue;
                }

                $jobMicroTimeStart = microtime(true);
                $this->output->write('[' . $i . '] ' . $cronJobName . ' ... ' , false);

                if (!empty($cronJob['schedule'])) {
                    $this->output->write('(schedule: ' . $cronJob['schedule'] . ') ' , false);
                }

                if (empty($cronJob['instance'])) {
                    $this->output->write('has no instance defined', true);
                    continue;
                }

                if ($i < $offset) {
                    $this->output->write('skipped', true);
                    continue;
                }

                $schedule = $this->objectManager->get(\Magento\Cron\Model\Schedule::class);

                try {
                    $cronJobInstance = $this->objectManager->get($cronJob['instance']);
                    $cronJobInstance->{$cronJob['method']}($schedule);
                } catch (\ErrorException $e) {
                    $errors[] = [
                        'job_name' => $cronJob['name'],
                        'job' => $cronJob,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'code' => $e->getCode(),
                        'trace' => $e->getTrace()
                    ];
                } catch (\Exception $e) {
                    $errors[] = [
                        'job_name' => $cronJob['name'],
                        'job' => $cronJob,
                        'message' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'code' => $e->getCode(),
                        'trace' => $e->getTrace()
                    ];//ErrorException
                } finally {
                    $jobMicroTimeEnd = microtime(true);
                    $jobMicroTimeDiff = $jobMicroTimeEnd - $jobMicroTimeStart;

                    $this->output->write('[' . $this->formatTime($jobMicroTimeDiff) . ']', true);
                }
            }
        }

        $globalMicroTimeEnd = microtime(true);
        $microTimeDiff = $globalMicroTimeEnd - $globalMicroTimeStart;


        $this->output->write('', true);
        $this->output->write('[FINISH] All cron jobs were executed successfully in '
            . $this->formatTime($microTimeDiff), true);

        $this->output->write('', true);


        return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
    }
}