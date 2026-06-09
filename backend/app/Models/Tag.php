<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = [
        'name',
        'slug',
    ];

    public function posts()
    {
        return $this->belongsToMany(Post::class, 'post_tags');
    }

    public static function findByName(string $name): ?self
    {
        return self::query()->where('name', $name)->first();
    }

    public static function createFromName(string $name): self
    {
        return self::create([
            'name' => $name,
            'slug' => self::makeUniqueSlug($name),
        ]);
    }

    public static function makeUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            $slug = 'tag-'.substr(md5($name), 0, 12);
        }

        $original = $slug;
        $counter = 1;

        while (self::query()->where('slug', $slug)->exists()) {
            $slug = $original.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
