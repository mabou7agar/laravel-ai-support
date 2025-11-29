<?php

namespace LaravelAIEngine\Traits;

/**
 * VectorizableWithMedia Trait
 * 
 * Convenience trait that combines Vectorizable and HasMediaEmbeddings
 * Use this when you want both text and media vectorization
 * 
 * @example
 * class Post extends Model
 * {
 *     use VectorizableWithMedia;
 *     
 *     // That's it! Auto-detects text fields AND media fields
 * }
 */
trait VectorizableWithMedia
{
    use Vectorizable, HasMediaEmbeddings;
}
