<?php

declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\EventDispatcher\RabbitMq;

use Doctrine\ORM\EntityManagerInterface;
use DomainException;
use Exception;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Shlinkio\Shlink\Common\UpdatePublishing\PublishingHelperInterface;
use Shlinkio\Shlink\Common\UpdatePublishing\Update;
use Shlinkio\Shlink\Core\Entity\ShortUrl;
use Shlinkio\Shlink\Core\EventDispatcher\Event\ShortUrlCreated;
use Shlinkio\Shlink\Core\EventDispatcher\RabbitMq\NotifyNewShortUrlToRabbitMq;
use Shlinkio\Shlink\Core\EventDispatcher\Topic;
use Shlinkio\Shlink\Core\Options\RabbitMqOptions;
use Shlinkio\Shlink\Core\ShortUrl\Helper\ShortUrlStringifier;
use Shlinkio\Shlink\Core\ShortUrl\Transformer\ShortUrlDataTransformer;
use Throwable;

class NotifyNewShortUrlToRabbitMqTest extends TestCase
{
    use ProphecyTrait;

    private NotifyNewShortUrlToRabbitMq $listener;
    private ObjectProphecy $helper;
    private ObjectProphecy $em;
    private ObjectProphecy $logger;
    private RabbitMqOptions $options;

    protected function setUp(): void
    {
        $this->helper = $this->prophesize(PublishingHelperInterface::class);
        $this->em = $this->prophesize(EntityManagerInterface::class);
        $this->logger = $this->prophesize(LoggerInterface::class);
        $this->options = new RabbitMqOptions(['enabled' => true]);

        $this->listener = new NotifyNewShortUrlToRabbitMq(
            $this->helper->reveal(),
            $this->em->reveal(),
            $this->logger->reveal(),
            new ShortUrlDataTransformer(new ShortUrlStringifier([])),
            $this->options,
        );
    }

    /** @test */
    public function doesNothingWhenTheFeatureIsNotEnabled(): void
    {
        $this->options->enabled = false;

        ($this->listener)(new ShortUrlCreated('123'));

        $this->em->find(Argument::cetera())->shouldNotHaveBeenCalled();
        $this->logger->warning(Argument::cetera())->shouldNotHaveBeenCalled();
        $this->logger->debug(Argument::cetera())->shouldNotHaveBeenCalled();
        $this->helper->publishUpdate(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    /** @test */
    public function notificationsAreNotSentWhenShortUrlCannotBeFound(): void
    {
        $shortUrlId = '123';
        $find = $this->em->find(ShortUrl::class, $shortUrlId)->willReturn(null);
        $logWarning = $this->logger->warning(
            'Tried to notify RabbitMQ for new short URL with id "{shortUrlId}", but it does not exist.',
            ['shortUrlId' => $shortUrlId],
        );

        ($this->listener)(new ShortUrlCreated($shortUrlId));

        $find->shouldHaveBeenCalledOnce();
        $logWarning->shouldHaveBeenCalledOnce();
        $this->logger->debug(Argument::cetera())->shouldNotHaveBeenCalled();
        $this->helper->publishUpdate(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    /** @test */
    public function expectedChannelIsNotified(): void
    {
        $shortUrlId = '123';
        $find = $this->em->find(ShortUrl::class, $shortUrlId)->willReturn(ShortUrl::withLongUrl(''));

        ($this->listener)(new ShortUrlCreated($shortUrlId));

        $find->shouldHaveBeenCalledOnce();
        $this->helper->publishUpdate(
            Argument::that(fn (Update $update) => $update->topic === Topic::NEW_SHORT_URL->value),
        )->shouldHaveBeenCalledOnce();
        $this->logger->debug(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    /**
     * @test
     * @dataProvider provideExceptions
     */
    public function printsDebugMessageInCaseOfError(Throwable $e): void
    {
        $shortUrlId = '123';
        $find = $this->em->find(ShortUrl::class, $shortUrlId)->willReturn(ShortUrl::withLongUrl(''));
        $publish = $this->helper->publishUpdate(Argument::cetera())->willThrow($e);

        ($this->listener)(new ShortUrlCreated($shortUrlId));

        $this->logger->debug(
            'Error while trying to notify RabbitMQ with new short URL. {e}',
            ['e' => $e],
        )->shouldHaveBeenCalledOnce();
        $find->shouldHaveBeenCalledOnce();
        $publish->shouldHaveBeenCalledOnce();
    }

    public function provideExceptions(): iterable
    {
        yield [new RuntimeException('RuntimeException Error')];
        yield [new Exception('Exception Error')];
        yield [new DomainException('DomainException Error')];
    }
}
