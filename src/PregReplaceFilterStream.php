<?php
/**
 * This file is part of the ZBateson\StreamDecorators project.
 *
 * @license http://opensource.org/licenses/bsd-license.php BSD
 */

namespace ZBateson\StreamDecorators;

use GuzzleHttp\Psr7\BufferStream;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * Calls preg_replace on each read operation with the passed pattern and
 * replacement string.  Should only really be used to find single characters,
 * since a pattern intended to match more may be split across multiple read()
 * operations.
 *
 * @author Zaahid Bateson
 */
class PregReplaceFilterStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /**
     * @var string The regex pattern
     */
    private $pattern;

    /**
     * @var string The replacement
     */
    private $replacement;

    /**
     * @var BufferStream Buffered stream of input from the underlying stream
     */
    private $buffer;

    /**
     * @var StreamInterface $stream
     */
    private $stream;

    public function __construct(StreamInterface $stream, $pattern, $replacement)
    {
        $this->stream = $stream;
        $this->pattern = $pattern;
        $this->replacement = $replacement;
        $this->buffer = new BufferStream();
    }

    /**
     * Returns true if the end of stream has been reached.
     *
     * @return bool
     */
    public function eof()
    {
        return $this->buffer->eof() && $this->stream->eof();
    }

    /**
     * Not supported by PregReplaceFilterStream
     *
     * @param int $offset
     * @param int $whence
     * @throws RuntimeException
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        throw new RuntimeException('Cannot seek a PregReplaceFilterStream');
    }

    /**
     * Overridden to return false
     *
     * @return bool
     */
    public function isSeekable()
    {
        return false;
    }

    /**
     * Reads from the underlying stream, filters it and returns up to $length
     * bytes.
     *
     * @param int $length
     * @return string
     */
    public function read($length)
    {
        $this->fillBuffer($length);

        return $this->buffer->read($length);
    }

    /**
     * Fills the BufferStream with at least 8192 characters of input for future
     * read operations.
     *
     * @param int $length
     */
    private function fillBuffer($length)
    {
        $fill = (int)\max([$length, 8192]);

        while ($this->buffer->getSize() < $length) {
            $read = $this->stream->read($fill);

            if (false === $read || '' === $read) {
                break;
            }
            $this->buffer->write(\preg_replace($this->pattern, $this->replacement, $read));
        }
    }
}
