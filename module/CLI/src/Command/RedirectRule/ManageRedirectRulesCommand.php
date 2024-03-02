<?php

declare(strict_types=1);

namespace Shlinkio\Shlink\CLI\Command\RedirectRule;

use Doctrine\Common\Collections\ArrayCollection;
use Shlinkio\Shlink\CLI\Util\ExitCode;
use Shlinkio\Shlink\Core\Exception\InvalidArgumentException;
use Shlinkio\Shlink\Core\Exception\ShortUrlNotFoundException;
use Shlinkio\Shlink\Core\Model\DeviceType;
use Shlinkio\Shlink\Core\RedirectRule\Entity\RedirectCondition;
use Shlinkio\Shlink\Core\RedirectRule\Entity\ShortUrlRedirectRule;
use Shlinkio\Shlink\Core\RedirectRule\Model\RedirectConditionType;
use Shlinkio\Shlink\Core\RedirectRule\ShortUrlRedirectRuleServiceInterface;
use Shlinkio\Shlink\Core\ShortUrl\Entity\ShortUrl;
use Shlinkio\Shlink\Core\ShortUrl\Model\ShortUrlIdentifier;
use Shlinkio\Shlink\Core\ShortUrl\Model\Validation\ShortUrlInputFilter;
use Shlinkio\Shlink\Core\ShortUrl\ShortUrlResolverInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_flip;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function is_numeric;
use function max;
use function min;
use function Shlinkio\Shlink\Core\ArrayUtils\map;
use function Shlinkio\Shlink\Core\enumValues;
use function sprintf;
use function str_pad;
use function strlen;
use function trim;

use const STR_PAD_LEFT;

class ManageRedirectRulesCommand extends Command
{
    public const NAME = 'short-url:manage-rules';

    public function __construct(
        protected readonly ShortUrlResolverInterface $shortUrlResolver,
        protected readonly ShortUrlRedirectRuleServiceInterface $ruleService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName(self::NAME)
            ->setDescription('Set redirect rules for a short URL')
            ->addArgument('shortCode', InputArgument::REQUIRED, 'The short code which rules we want to set.')
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'The domain for the short code.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = ShortUrlIdentifier::fromCli($input);

        try {
            $shortUrl = $this->shortUrlResolver->resolveShortUrl($identifier);
        } catch (ShortUrlNotFoundException) {
            $io->error(sprintf('Short URL for %s not found', $identifier->__toString()));
            return ExitCode::EXIT_FAILURE;
        }

        $rulesToSave = $this->processRules($shortUrl, $io, $this->ruleService->rulesForShortUrl($shortUrl));
        if ($rulesToSave !== null) {
            $this->ruleService->saveRulesForShortUrl($shortUrl, $rulesToSave);
        }

        return ExitCode::EXIT_SUCCESS;
    }

    /**
     * @param ShortUrlRedirectRule[] $rules
     * @return ShortUrlRedirectRule[]|null
     */
    private function processRules(ShortUrl $shortUrl, SymfonyStyle $io, array $rules): ?array
    {
        $amountOfRules = count($rules);

        if ($amountOfRules === 0) {
            $io->comment('<comment>No rules found.</comment>');
        } else {
            $listing = map(
                $rules,
                function (ShortUrlRedirectRule $rule, string|int|float $index) use ($amountOfRules): array {
                    $priority = ((int) $index) + 1;
                    $conditions = $rule->mapConditions(static fn (RedirectCondition $condition): string => sprintf(
                        '<comment>%s</comment>',
                        $condition->toHumanFriendly(),
                    ));

                    return [
                        str_pad((string) $priority, strlen((string) $amountOfRules), '0', STR_PAD_LEFT),
                        implode(' AND ', $conditions),
                        $rule->longUrl,
                    ];
                },
            );
            $io->table(['Priority', 'Conditions', 'Redirect to'], $listing);
        }

        $action = $io->choice(
            'What do you want to do next?',
            [
                'Add new rule',
                'Remove existing rule',
                'Re-arrange rule',
                'Discard changes',
                'Save and exit',
            ],
            'Save and exit',
        );

        return match ($action) {
            'Add new rule' => $this->processRules($shortUrl, $io, $this->addRule($shortUrl, $io, $rules)),
            'Remove existing rule' => $this->processRules($shortUrl, $io, $this->removeRule($io, $rules)),
            'Re-arrange rule' => $this->processRules($shortUrl, $io, $this->reArrangeRule($io, $rules)),
            'Save and exit' => $rules,
            default => null,
        };
    }

    /**
     * @param ShortUrlRedirectRule[] $currentRules
     */
    private function addRule(ShortUrl $shortUrl, SymfonyStyle $io, array $currentRules): array
    {
        $higherPriority = count($currentRules);
        $priority = $this->askPriority($io, $higherPriority + 1);
        $longUrl = $this->askLongUrl($io);
        $conditions = [];

        do {
            $type = RedirectConditionType::from(
                $io->choice('Type of the condition?', enumValues(RedirectConditionType::class)),
            );
            $conditions[] = match ($type) {
                RedirectConditionType::DEVICE => RedirectCondition::forDevice(
                    DeviceType::from($io->choice('Device to match?', enumValues(DeviceType::class))),
                ),
                RedirectConditionType::LANGUAGE => RedirectCondition::forLanguage(
                    $this->askMandatory('Language to match?', $io),
                ),
                RedirectConditionType::QUERY_PARAM => RedirectCondition::forQueryParam(
                    $this->askMandatory('Query param name?', $io),
                    $this->askOptional('Query param value?', $io),
                ),
            };

            $continue = $io->confirm('Do you want to add another condition?');
        } while ($continue);

        $newRule = new ShortUrlRedirectRule($shortUrl, $priority, $longUrl, new ArrayCollection($conditions));
        $rulesBefore = array_slice($currentRules, 0, $priority - 1);
        $rulesAfter = array_slice($currentRules, $priority - 1);

        return [...$rulesBefore, $newRule, ...$rulesAfter];
    }

    /**
     * @param ShortUrlRedirectRule[] $currentRules
     */
    private function removeRule(SymfonyStyle $io, array $currentRules): array
    {
        if (empty($currentRules)) {
            $io->warning('There are no rules to remove');
            return $currentRules;
        }

        $index = $this->askRule('What rule do you want to delete?', $io, $currentRules);
        unset($currentRules[$index]);
        return array_values($currentRules);
    }

    /**
     * @param ShortUrlRedirectRule[] $currentRules
     */
    private function reArrangeRule(SymfonyStyle $io, array $currentRules): array
    {
        if (empty($currentRules)) {
            $io->warning('There are no rules to re-arrange');
            return $currentRules;
        }

        $oldIndex = $this->askRule('What rule do you want to re-arrange?', $io, $currentRules);
        $newIndex = $this->askPriority($io, count($currentRules)) - 1;

        // Temporarily get rule from array and unset it
        $rule = $currentRules[$oldIndex];
        unset($currentRules[$oldIndex]);

        // Reindex remaining rules
        $currentRules = array_values($currentRules);

        $rulesBefore = array_slice($currentRules, 0, $newIndex);
        $rulesAfter = array_slice($currentRules, $newIndex);

        return [...$rulesBefore, $rule, ...$rulesAfter];
    }

    /**
     * @param ShortUrlRedirectRule[] $currentRules
     */
    private function askRule(string $message, SymfonyStyle $io, array $currentRules): int
    {
        $choices = [];
        foreach ($currentRules as $index => $rule) {
            $choices[$rule->longUrl] = $index + 1;
        }

        $resp = $io->choice($message, array_flip($choices));
        return $choices[$resp] - 1;
    }

    private function askPriority(SymfonyStyle $io, int $max): int
    {
        return $io->ask(
            'Rule priority (the lower the value, the higher the priority)',
            (string) $max,
            function (string $answer) use ($max): int {
                if (! is_numeric($answer)) {
                    throw new InvalidArgumentException('The priority must be a numeric positive value');
                }

                $priority = (int) $answer;
                return max(1, min($max, $priority));
            },
        );
    }

    private function askLongUrl(SymfonyStyle $io): string
    {
        return $io->ask(
            'Long URL to redirect when the rule matches',
            validator: function (string $answer): string {
                $validator = ShortUrlInputFilter::longUrlValidators();
                if (! $validator->isValid($answer)) {
                    throw new InvalidArgumentException(implode(', ', $validator->getMessages()));
                }

                return $answer;
            },
        );
    }

    private function askMandatory(string $message, SymfonyStyle $io): string
    {
        return $io->ask($message, validator: function (?string $answer): string {
            if ($answer === null) {
                throw new InvalidArgumentException('The value is mandatory');
            }
            return trim($answer);
        });
    }

    private function askOptional(string $message, SymfonyStyle $io): string
    {
        return $io->ask($message, validator: fn (?string $answer) => $answer === null ? '' : trim($answer));
    }
}
