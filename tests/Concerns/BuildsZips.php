<?php

namespace Tests\Concerns;

use ZipArchive;

trait BuildsZips
{
    /** Builds a ZIP in a temp file. Each entry: name => content, or name => [content, unixMode]. */
    protected function zip(array $entries, ?callable $tweak = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip-test-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            [$body, $mode] = is_array($content) ? $content : [$content, 0100644];
            str_ends_with($name, '/') ? $zip->addEmptyDir(rtrim($name, '/')) : $zip->addFromString($name, $body);
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, $mode << 16);
        }

        $tweak && $tweak($zip);
        $zip->close();

        return $path;
    }

    /** A real-looking Laravel project inside a wrapper folder, with a big vendor/ and a sentinel. */
    protected function laravelZip(string $prefix = 'myapp/', int $vendorFiles = 500): string
    {
        $entries = [
            "{$prefix}artisan" => "#!/usr/bin/env php\n<?php\n",
            "{$prefix}composer.json" => '{"require":{"laravel/framework":"^11.0"}}',
            "{$prefix}routes/api.php" => "<?php\nRoute::get('/users', fn () => []);\n",
            // Would prove execution: if anything ever runs uploaded PHP, this file appears.
            "{$prefix}app/Sentinel.php" => "<?php\nfile_put_contents('".$this->sentinelPath()."', 'executed');\n",
            "{$prefix}.env" => "APP_KEY=ZIP-SECRET-DO-NOT-STORE\n",
            "{$prefix}storage/bundle.zip" => 'PK fake nested archive with ZIP-NESTED-CONTENT',
        ];

        for ($i = 0; $i < $vendorFiles; $i++) {
            $entries["{$prefix}vendor/pkg{$i}/src/File.php"] = "<?php // vendor file {$i}\n";
        }

        return $this->zip($entries);
    }

    protected function sentinelPath(): string
    {
        return sys_get_temp_dir().'/api-engine-zip-sentinel';
    }
}
