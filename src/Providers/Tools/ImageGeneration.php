<?php

namespace Laravel\Ai\Providers\Tools;

class ImageGeneration extends ProviderTool
{
    /**
     * @param  array<string, mixed>  $options  Additional provider-specific options (e.g. action, model, moderation, input_fidelity).
     */
    public function __construct(
        public ?string $background = null,
        public ?array $inputImageMask = null,
        public ?int $outputCompression = null,
        public ?string $outputFormat = null,
        public ?int $partialImages = null,
        public ?string $quality = null,
        public ?string $size = null,
        public array $options = [],
    ) {
        if ($partialImages !== null && ($partialImages < 0 || $partialImages > 3)) {
            throw new \InvalidArgumentException('partial_images must be between 0 and 3.');
        }

        if ($outputCompression !== null && ($outputCompression < 0 || $outputCompression > 100)) {
            throw new \InvalidArgumentException('output_compression must be between 0 and 100.');
        }
    }

    /**
     * Set the number of partial images to stream.
     */
    public function partial(int $count): self
    {
        if ($count < 0 || $count > 3) {
            throw new \InvalidArgumentException('partial_images must be between 0 and 3.');
        }

        $this->partialImages = $count;

        return $this;
    }

    /**
     * Set the output format.
     */
    public function format(string $format): self
    {
        $this->outputFormat = $format;

        return $this;
    }

    /**
     * Set the output compression level.
     */
    public function compression(int $level): self
    {
        if ($level < 0 || $level > 100) {
            throw new \InvalidArgumentException('output_compression must be between 0 and 100.');
        }

        $this->outputCompression = $level;

        return $this;
    }

    /**
     * Set the image quality.
     */
    public function quality(string $quality): self
    {
        $this->quality = $quality;

        return $this;
    }

    /**
     * Set the image size.
     */
    public function size(string $size): self
    {
        $this->size = $size;

        return $this;
    }

    /**
     * Set the background transparency mode.
     */
    public function background(string $background): self
    {
        $this->background = $background;

        return $this;
    }

    /**
     * Set the input image mask.
     */
    public function mask(?string $fileId = null, ?string $imageUrl = null): self
    {
        $filtered = array_filter([
            'file_id' => $fileId,
            'image_url' => $imageUrl,
        ]);

        $this->inputImageMask = $filtered ?: null;

        return $this;
    }

    /**
     * Set additional provider-specific options.
     */
    public function withOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);

        return $this;
    }
}
