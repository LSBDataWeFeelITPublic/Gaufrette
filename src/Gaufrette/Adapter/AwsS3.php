<?php

namespace Gaufrette\Adapter;

use Aws\S3\Exception\S3Exception;
use Gaufrette\Adapter;
use Aws\S3\S3Client;
use Gaufrette\Exception\FileNotFound;
use Gaufrette\Exception\StorageFailure;
use Gaufrette\Util;

/**
 * Amazon S3 adapter using the AWS SDK for PHP v2.x.
 *
 * @author  Michael Dowling <mtdowling@gmail.com>
 */
class AwsS3 implements Adapter, MetadataSupporter, ListKeysAware, SizeCalculator, MimeTypeProvider
{
    /** @var S3Client */
    protected $service;
    /** @var string */
    protected $bucket;
    /** @var array */
    protected $options;
    /** @var bool */
    protected $bucketExists;
    /** @var array */
    protected $metadata = [];
    /** @var bool */
    protected $detectContentType;

    /**
     * @param S3Client $service
     * @param string   $bucket
     * @param array    $options
     * @param bool     $detectContentType
     */
    public function __construct(S3Client $service, $bucket, array $options = [], $detectContentType = false)
    {
        $this->service = $service;
        $this->bucket = $bucket;
        $this->options = array_replace(
            [
                'create' => false,
                'directory' => '',
                'acl' => 'private',
            ],
            $options
        );

        // Remove trailing slash so it can't be doubled in computePath() method
        $this->options['directory'] = rtrim($this->options['directory'], '/');

        $this->detectContentType = $detectContentType;
    }

    /**
     * {@inheritdoc}
     */
    public function setMetadata($key, $metadata)
    {
        // BC with AmazonS3 adapter
        if (isset($metadata['contentType'])) {
            $metadata['ContentType'] = $metadata['contentType'];
            unset($metadata['contentType']);
        }

        $this->metadata[$key] = $metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($key)
    {
        return isset($this->metadata[$key]) ? $this->metadata[$key] : [];
    }

    /**
     * {@inheritdoc}
     */
    public function read($key)
    {
        $this->ensureBucketExists();
        $options = $this->getOptions($key);

        try {
            // Get remote object
            $object = $this->service->getObject($options);
            // If there's no metadata array set up for this object, set it up
            if (!array_key_exists($key, $this->metadata) || !is_array($this->metadata[$key])) {
                $this->metadata[$key] = [];
            }
            // Make remote ContentType metadata available locally
            $this->metadata[$key]['ContentType'] = $object->get('ContentType');

            return (string) $object->get('Body');
        } catch (\Exception $e) {
            if ($e instanceof S3Exception && $e->getResponse()->getStatusCode() === 404) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('read', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function rename($sourceKey, $targetKey)
    {
        $this->ensureBucketExists();
        $options = $this->getOptions(
            $targetKey,
            ['CopySource' => $this->bucket . '/' . $this->computePath($sourceKey)]
        );

        try {
            $this->service->copyObject(array_merge($options, $this->getMetadata($targetKey)));

            $this->delete($sourceKey);
        } catch (\Exception $e) {
            if ($e instanceof S3Exception && $e->getResponse()->getStatusCode() === 404) {
                throw new FileNotFound($sourceKey);
            }

            throw StorageFailure::unexpectedFailure(
                'rename',
                ['sourceKey' => $sourceKey, 'targetKey' => $targetKey],
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write($key, $content)
    {
        $this->ensureBucketExists();
        $options = $this->getOptions($key, ['Body' => $content]);

        /*
         * If the ContentType was not already set in the metadata, then we autodetect
         * it to prevent everything being served up as binary/octet-stream.
         */
        if (!isset($options['ContentType']) && $this->detectContentType) {
            $options['ContentType'] = $this->guessContentType($content);
        }

        try {
            $this->service->putObject($options);
        } catch (\Exception $e) {
            throw StorageFailure::unexpectedFailure(
                'write',
                ['key' => $key, 'content' => $content],
                $e
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function exists($key)
    {
        $path = $this->computePath($key);

        try {
            return $this->service->doesObjectExist($this->bucket, $path);
        } catch (\Exception $exception) {
            throw StorageFailure::unexpectedFailure('exists', ['key' => $key], $exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function mtime($key)
    {
        try {
            $result = $this->service->headObject($this->getOptions($key));

            return strtotime($result['LastModified']);
        } catch (\Exception $e) {
            if ($e instanceof S3Exception && $e->getResponse()->getStatusCode() === 404) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('mtime', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function size($key)
    {
        try {
            $result = $this->service->headObject($this->getOptions($key));

            return $result['ContentLength'];
        } catch (\Exception $e) {
            if ($e instanceof S3Exception && $e->getResponse()->getStatusCode() === 404) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('size', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function keys()
    {
        return $this->listKeys();
    }

    /**
     * {@inheritdoc}
     */
    public function listKeys($prefix = '')
    {
        $this->ensureBucketExists();

        $options = [
            'Bucket' => $this->bucket,
            'Prefix' => $this->computePath($prefix),
        ];

        $keys = [];
        $objects = $this->service->getIterator('ListObjects', $options);

        try {
            foreach ($objects as $file) {
                $keys[] = $this->computeKey($file['Key']);
            }
        } catch (S3Exception $e) {
            throw StorageFailure::unexpectedFailure('listKeys', ['prefix' => $prefix], $e);
        }

        return $keys;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key)
    {
        try {
            $this->service->deleteObject($this->getOptions($key));
        } catch (\Exception $e) {
            if ($e instanceof S3Exception && $e->getResponse()->getStatusCode() === 404) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('delete', ['key' => $key], $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isDirectory($key)
    {
        $result = $this->service->listObjects([
            'Bucket' => $this->bucket,
            'Prefix' => rtrim($this->computePath($key), '/') . '/',
            'MaxKeys' => 1,
        ]);
        if (isset($result['Contents'])) {
            if (is_array($result['Contents']) || $result['Contents'] instanceof \Countable) {
                return count($result['Contents']) > 0;
            }
        }

        return false;
    }

    /**
     * Ensures the specified bucket exists. If the bucket does not exists
     * and the create option is set to true, it will try to create the
     * bucket. The bucket is created using the same region as the supplied
     * client object.
     *
     * @throws \RuntimeException if the bucket does not exists or could not be
     *                           created
     */
    protected function ensureBucketExists()
    {
        if ($this->bucketExists) {
            return true;
        }

        if ($this->bucketExists = $this->service->doesBucketExist($this->bucket)) {
            return true;
        }

        if (!$this->options['create']) {
            throw new \RuntimeException(sprintf(
                'The configured bucket "%s" does not exist.',
                $this->bucket
            ));
        }

        $this->service->createBucket([
            'Bucket' => $this->bucket,
            'LocationConstraint' => $this->service->getRegion(),
        ]);
        $this->bucketExists = true;

        return true;
    }

    protected function getOptions($key, array $options = [])
    {
        $options['ACL'] = $this->options['acl'];
        $options['Bucket'] = $this->bucket;
        $options['Key'] = $this->computePath($key);

        /*
         * Merge global options for adapter, which are set in the constructor, with metadata.
         * Metadata will override global options.
         */
        $options = array_merge($this->options, $options, $this->getMetadata($key));

        return $options;
    }

    protected function computePath($key)
    {
        if (empty($this->options['directory'])) {
            return $key;
        }

        return sprintf('%s/%s', $this->options['directory'], $key);
    }

    /**
     * Computes the key from the specified path.
     *
     * @param string $path
     *
     * return string
     */
    protected function computeKey($path)
    {
        return ltrim(substr($path, strlen($this->options['directory'])), '/');
    }

    /**
     * @param string $content
     *
     * @return string
     */
    private function guessContentType($content)
    {
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);

        if (is_resource($content)) {
            return $fileInfo->file(stream_get_meta_data($content)['uri']);
        }

        return $fileInfo->buffer($content);
    }

    public function mimeType($key)
    {
        try {
            $result = $this->service->headObject($this->getOptions($key));

            return ($result['ContentType']);
        } catch (\Exception $e) {
            if ($e instanceof S3Exception && $e->getResponse()->getStatusCode() === 404) {
                throw new FileNotFound($key);
            }

            throw StorageFailure::unexpectedFailure('mimeType', ['key' => $key], $e);
        }
    }
}
