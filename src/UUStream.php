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
 * GuzzleHttp\Psr7 stream decoder extension for UU-Encoded streams.
 *
 * The size of the underlying stream and the position of bytes can't be
 * determined because the number of encoded bytes is indeterminate without
 * reading the entire stream.
 *
 * @author Zaahid Bateson
 */
class UUStream implements StreamInterface
{
    use StreamDecoratorTrait;

    /**
     * @var string name of the UUEncoded file
     */
    protected $filename = null;

    /**
     * @var BufferStream of read and decoded bytes
     */
    private $buffer;

    /**
     * @var string remainder of write operation if the bytes didn't align to 3
     *      bytes
     */
    private $remainder = '';

    /**
     * @var int read/write position
     */
    private $position = 0;

    /**
     * @var bool set to true when 'write' is called
     */
    private $isWriting = false;

    /**
     * @var StreamInterface $stream
     */
    private $stream;

    /**
     * @param StreamInterface $stream Stream to decorate
     * @param string $filename optional file name
     */
    public function __construct(StreamInterface $stream, $filename = null)
    {
        $this->stream = $stream;
        $this->filename = $filename;
        $this->buffer = new BufferStream();
    }

    /**
     * Overridden to return the position in the target encoding.
     *
     * @return int
     */
    public function tell()
    {
        return $this->position;
    }

    /**
     * Returns null, getSize isn't supported
     *
     * @return null
     */
    public function getSize()
    {

    }

    /**
     * Not supported.
     *
     * @param int $offset
     * @param int $whence
     * @throws RuntimeException
     */
    public function seek($offset, $whence = SEEK_SET)
    {
        throw new RuntimeException('Cannot seek a UUStream');
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
     * Returns true if the end of stream has been reached.
     *
     * @return bool
     */
    public function eof()
    {
        return $this->buffer->eof() && $this->stream->eof();
    }

    /**
     * Attempts to read $length bytes after decoding them, and returns them.
     *
     * @param int $length
     * @return string
     */
    public function read($length)
    {
        // let Guzzle decide what to do.
        if ($length <= 0 || $this->eof()) {
            return $this->stream->read($length);
        }
        $this->fillBuffer($length);
        $read = $this->buffer->read($length);
        $this->position += \strlen($read);

        return $read;
    }

    /**
     * Writes the passed string to the underlying stream after encoding it.
     *
     * Note that reading and writing to the same stream without rewinding is not
     * supported.
     *
     * Also note that some bytes may not be written until close or detach are
     * called.  This happens if written data doesn't align to a complete
     * uuencoded 'line' of 45 bytes.  In addition, the UU footer is only written
     * when closing or detaching as well.
     *
     * @param string $string
     * @return int the number of bytes written
     */
    public function write($string)
    {
        $this->isWriting = true;

        if (0 === $this->position) {
            $this->writeUUHeader();
        }
        $write = $this->handleRemainder($string);

        if ('' !== $write) {
            $this->writeEncoded($write);
        }
        $written = \strlen($string);
        $this->position += $written;

        return $written;
    }

    /**
     * Returns the filename set in the UUEncoded header (or null)
     *
     * @return string
     */
    public function getFilename()
    {
        return $this->filename;
    }

    /**
     * Sets the UUEncoded header file name written in the 'begin' header line.
     *
     * @param string $filename
     */
    public function setFilename($filename)
    {
        $this->filename = $filename;
    }

    /**
     * Writes any remaining bytes out followed by the uu-encoded footer, then
     * closes the stream.
     * @return void
     */
    public function close()
    {
        $this->beforeClose();
        $this->stream->close();
    }

    /**
     * Writes any remaining bytes out followed by the uu-encoded footer, then
     * detaches the stream.
     * @return resource|null Underlying PHP stream, if any
     */
    public function detach()
    {
        $this->beforeClose();
        $this->stream->detach();
    }

    /**
     * Finds the next end-of-line character to ensure a line isn't broken up
     * while buffering.
     *
     * @return string
     */
    private function readToEndOfLine($length)
    {
        $str = $this->stream->read($length);

        if (false === $str || '' === $str) {
            return $str;
        }

        while ("\n" !== \substr($str, -1)) {
            $chr = $this->stream->read(1);

            if (false === $chr || '' === $chr) {
                break;
            }
            $str .= $chr;
        }

        return $str;
    }

    /**
     * Removes invalid characters from a uuencoded string, and 'BEGIN' and 'END'
     * line headers and footers from the passed string before returning it.
     *
     * @param string $str
     * @return string
     */
    private function filterAndDecode($str)
    {
        $ret = \str_replace("\r", '', $str);
        $ret = \preg_replace('/[^\x21-\xf5`\n]/', '`', $ret);

        if (0 === $this->position) {
            $matches = [];

            if (\preg_match('/^\s*begin\s+[^\s+]\s+([^\r\n]+)\s*$/im', $ret, $matches)) {
                $this->filename = $matches[1];
            }
            $ret = \preg_replace('/^\s*begin[^\r\n]+\s*$/im', '', $ret);
        } else {
            $ret = \preg_replace('/^\s*end\s*$/im', '', $ret);
        }

        return \convert_uudecode(\trim($ret));
    }

    /**
     * Buffers bytes into $this->buffer, removing uuencoding headers and footers
     * and decoding them.
     */
    private function fillBuffer($length)
    {
        // 5040 = 63 * 80, seems to be good balance for buffering in benchmarks
        // testing with a simple 'if ($length < x)' and calculating a better
        // size reduces speeds by up to 4x
        while ($this->buffer->getSize() < $length) {
            $read = $this->readToEndOfLine(5040);

            if (false === $read || '' === $read) {
                break;
            }
            $this->buffer->write($this->filterAndDecode($read));
        }
    }

    /**
     * Writes the 'begin' UU header line.
     */
    private function writeUUHeader()
    {
        $filename = (empty($this->filename)) ? 'null' : $this->filename;
        $this->stream->write("begin 666 {$filename}");
    }

    /**
     * Writes the '`' and 'end' UU footer lines.
     */
    private function writeUUFooter()
    {
        $this->stream->write("\r\n`\r\nend\r\n");
    }

    /**
     * Writes the passed bytes to the underlying stream after encoding them.
     *
     * @param string $bytes
     */
    private function writeEncoded($bytes)
    {
        $encoded = \preg_replace('/\r\n|\r|\n/', "\r\n", \rtrim(\convert_uuencode($bytes)));
        // removes ending '`' line
        $this->stream->write("\r\n" . \rtrim(\substr($encoded, 0, -1)));
    }

    /**
     * Prepends any existing remainder to the passed string, then checks if the
     * string fits into a uuencoded line, and removes and keeps any remainder
     * from the string to write.  Full lines ready for writing are returned.
     *
     * @param string $string
     * @return string
     */
    private function handleRemainder($string)
    {
        $write = $this->remainder . $string;
        $nRem = \strlen($write) % 45;
        $this->remainder = '';

        if (0 !== $nRem) {
            $this->remainder = \substr($write, -$nRem);
            $write = \substr($write, 0, -$nRem);
        }

        return $write;
    }

    /**
     * Writes out any remaining bytes and the UU footer.
     */
    private function beforeClose()
    {
        if (! $this->isWriting) {
            return;
        }

        if ('' !== $this->remainder) {
            $this->writeEncoded($this->remainder);
        }
        $this->remainder = '';
        $this->isWriting = false;
        $this->writeUUFooter();
    }
}
