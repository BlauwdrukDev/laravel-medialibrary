<?php

namespace Spatie\MediaLibrary;

use ZipStream\ZipStream;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\Models\Media;
use Illuminate\Contracts\Support\Responsable;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaStream implements Responsable
{
    /** string */
    protected $zipName;

    /** Illuminate\Support\Collection */
    protected $mediaItems;

    public static function create(string $zipName)
    {
        return new static($zipName);
    }

    public function __construct(string $zipName)
    {
        $this->zipName = $zipName;

        $this->mediaItems = collect();
    }

    public function addMedia(...$mediaItems)
    {
        collect($mediaItems)
            ->flatMap(function ($item) {
                if ($item instanceof Media) {
                    return [$item];
                }

                if ($item instanceof Collection) {
                    return $item->reduce(function (array $carry, Media $media) {
                        $carry[] = $media;

                        return $carry;
                    }, []);
                }

                return $item;
            })
            ->each(function (Media $media) {
                $this->mediaItems->push($media);
            });

        return $this;
    }

    public function getMediaItems(): Collection
    {
        return $this->mediaItems;
    }

    public function toResponse($request)
    {
        $headers = [
            'Content-Disposition' => "attachment; filename=\"{$this->zipName}\"",
            'Content-Type' => 'application/octet-stream',
        ];

        return new StreamedResponse(function () {
            return $this->getZipStream();
        }, 200, $headers);
    }

    public function getZipStream(): ZipStream
    {
        // For ZipStream-PHP versions above 1.0
        // improve performance using options
        if (class_exists('\ZipStream\Option\Archive')) {
            $options = new \ZipStream\Option\Archive();
            // Stream files without rewind.
            $options->setZeroHeader(true);
            $zip = new ZipStream($this->zipName, $options);
        } else {
            $zip = new ZipStream($this->zipName);
        }

        $this->getZipStreamContents()->each(function (array $mediaInZip) use ($zip) {
            $stream = $mediaInZip['media']->stream();

            $zip->addFileFromStream($mediaInZip['fileNameInZip'], $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        });

        $zip->finish();

        return $zip;
    }

    protected function getZipStreamContents(): Collection
    {
        return $this->mediaItems->map(function (Media $media, $mediaItemIndex) {
            return [
                'fileNameInZip' => $this->getFileNameWithSuffix($this->mediaItems, $mediaItemIndex),
                'media' => $media,
            ];
        });
    }

    protected function getFileNameWithSuffix(Collection $mediaItems, int $currentIndex): string
    {
        $fileNameCount = 0;

        $fileName = $mediaItems[$currentIndex]->file_name;

        foreach ($mediaItems as $index => $media) {
            if ($index >= $currentIndex) {
                break;
            }

            if ($media->file_name === $fileName) {
                $fileNameCount++;
            }
        }

        if ($fileNameCount === 0) {
            return $fileName;
        }

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        $fileNameWithoutExtension = pathinfo($fileName, PATHINFO_FILENAME);

        return "{$fileNameWithoutExtension} ({$fileNameCount}).{$extension}";
    }
}
