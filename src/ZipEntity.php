<?php
namespace Jazor\Zip\Store;

class ZipEntity
{
    private $compressVersion = 0x031e;
    private $decompressVersion = 0xa;
    private $flag = 0;
    private $compressionMethod = 0;
    private $lastModifyTime = 0;
    private $lastModifyDate = 0;
    private $crc32 = 0;
    private $compressedSize = 0;
    private $uncompressedSize = 0;
    private $fileNameLength = 0;
    private $extraFieldLength = 0;
    private $fileCommentLength = 0;
    private $diskNumberStart = 0;
    private $internalFileAttributes = 0;
    private $externalFileAttributes = 0;
    private $offset = 0;
    private $fileName = '';
    private $extraField = '';
    private $fileComment = '';
    /**
     * @var null
     */
    private $file;

    /**
     * ZipEntity constructor.
     * @param null $file
     * @param null $name
     * @throws \Exception
     */
    public function __construct($file = null, $name = null)
    {
        if (empty($file)) return;
        if (!is_file($file)) throw new \Exception(sprintf('file \'%s\' not found.', $file));

        if (empty($name)) {
            $pos = strrpos($file, DIRECTORY_SEPARATOR);
            if ($pos === false) throw new \Exception(sprintf('file path \'%s\' error.', $file));
            $name = substr($file, $pos + 1);
        }

        $name = str_replace('\\', '/', $name);

        $this->fileName = $name;
        $this->fileNameLength = strlen($name);

        if (strlen($name) != mb_strlen($name, 'utf-8')) {
            $this->setUtf8EncodingFlag();
        }

        $fileModifyTime = filemtime($file);
        $fileSize = filesize($file);

        $this->uncompressedSize = $this->compressedSize = $fileSize;

        $this->setLastModifyDate($fileModifyTime);
        $this->setLastModifyTime($fileModifyTime);

        $this->file = $file;
    }

    /**
     * 0x5455, 0x7875
     * @param int $id
     * @param $data
     */
    private function addExtra(int $id, $data)
    {
        $this->extraField .= pack('vv', $id, strlen($data)) . $data;
        $this->extraFieldLength = strlen($this->extraField);
    }

    public function getLastModifyDateString()
    {
        $dt = $this->lastModifyDate;
        return sprintf('%u-%02u-%02u', 1980 + (($dt >> 9) & 0X7F), ($dt >> 5) & 0XF, $dt & 0X1F);
    }

    public function getLastModifyTimeString()
    {
        $dt = $this->lastModifyTime;
        return sprintf('%02u:%02u:%02u', ($dt >> 11) & 0X1F, ($dt >> 5) & 0X3F, ($dt & 0X1F) * 2);
    }

    public function setUtf8EncodingFlag($utf8 = true)
    {
        if ($utf8) {
            $this->flag |= 0x0800;
            return;
        }
        $this->flag &= 0xf7ff;
    }

    public function setCRC32Flag($endOfData = true)
    {
        if ($endOfData) {
            $this->flag |= 0x0008;
            return;
        }
        $this->flag &= 0xfff7;
    }

    /**
     * @param int $time
     */
    public function setLastModifyTime(int $time)
    {
        $hour = intval(date('G', $time));
        $minute = intval(ltrim(date('i', $time), '0'));
        $second = floor(intval(ltrim(date('s', $time), '0')) / 2);

        $this->lastModifyTime = (($hour & 0X1F) << 11) | (($minute & 0x3f) << 5) | ($second & 0x1f);
    }

    /**
     * @param int $time
     */
    public function setLastModifyDate(int $time)
    {
        $year = intval(date('Y', $time)) - 1980;
        $month = intval(date('n', $time));
        $day = intval(date('j', $time));

        $this->lastModifyDate = (($year & 0X7F) << 9) | (($month & 0xf) << 5) | ($day & 0x1f);
    }

    public function getLastModify()
    {
        return $this->getLastModifyDateString() . ' ' . $this->getLastModifyTimeString();
    }

    /**
     * @param int $offset
     */
    public function setOffset(int $offset)
    {
        $this->offset = $offset;
    }

    public function getLocalFileHeader()
    {
        return pack('NvvvvvVVVvv', 0x504b0304,
                $this->decompressVersion,
                $this->flag,
                $this->compressionMethod,
                $this->lastModifyTime,
                $this->lastModifyDate,
                $this->crc32,
                $this->compressedSize,
                $this->uncompressedSize,
                $this->fileNameLength,
                $this->extraFieldLength) . $this->fileName . $this->extraField;
    }

    public function getLocalEntitySize()
    {
        return 30 + strlen($this->fileName) + $this->uncompressedSize + $this->extraFieldLength;
    }

    public function getCentralFileHeader()
    {
        return pack('NvvvvvvVVVvvvvvVV', 0x504b0102,
                $this->compressVersion,
                $this->decompressVersion,
                $this->flag,
                $this->compressionMethod,
                $this->lastModifyTime,
                $this->lastModifyDate,
                $this->crc32,
                $this->compressedSize,
                $this->uncompressedSize,
                $this->fileNameLength,
                $this->extraFieldLength,
                $this->fileCommentLength,
                $this->diskNumberStart,
                $this->internalFileAttributes,
                $this->externalFileAttributes,
                $this->offset) . $this->fileName . $this->extraField . $this->fileComment;
    }

    public function getCentralEntitySize()
    {
        return 46 + strlen($this->fileName) + $this->extraFieldLength + $this->fileCommentLength;
    }


    public function writeTo($output)
    {
        $this->crc32 = unpack('N', hash_file('crc32b', $this->file, true))[1];
        fwrite($output, $this->getLocalFileHeader());

        $input = fopen($this->file, 'rb');
        if (!$input) throw new \Exception('can not open file for read: ' . $this->file);
        try {
            while (!feof($input)) {
                fwrite($output, fread($input, 0x10000));
            }
        } finally {
            fclose($input);
        }
    }
}
