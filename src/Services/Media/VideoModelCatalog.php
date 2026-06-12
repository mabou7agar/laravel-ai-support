<?php

declare(strict_types=1);

namespace LaravelAIEngine\Services\Media;

use LaravelAIEngine\DTOs\VideoModelSpec;
use LaravelAIEngine\Enums\EntityEnum;

/**
 * Single source of truth for every video model the package can drive.
 *
 * Each entry maps a model identifier to a {@see VideoModelSpec} describing its
 * provider, endpoint, supported options and first/last frame field names. The
 * FAL and Replicate drivers build their payloads from these specs, and
 * EntityEnum resolves content-type / credit / engine for video models here so a
 * new variant only needs one catalog entry (plus a constant for discoverability).
 *
 * Option whitelists and field names come from each provider's live API schema.
 */
final class VideoModelCatalog
{
    /** @var array<string, VideoModelSpec>|null */
    private static ?array $specs = null;

    public static function get(string $model): ?VideoModelSpec
    {
        return self::specs()[$model] ?? null;
    }

    public static function isVideo(string $model): bool
    {
        return isset(self::specs()[$model]);
    }

    /** @return array<string, VideoModelSpec> */
    public static function all(): array
    {
        return self::specs();
    }

    /** @return array<string, VideoModelSpec> */
    private static function specs(): array
    {
        if (self::$specs !== null) {
            return self::$specs;
        }

        $specs = [];

        // Shared option fragments -------------------------------------------------
        $seed = ['seed' => ['field' => 'seed', 'cast' => 'int']];
        $negativePrompt = ['negative_prompt' => ['field' => 'negative_prompt', 'cast' => 'string']];
        $cfgScale = ['cfg_scale' => ['field' => 'cfg_scale', 'cast' => 'float']];

        // --- Seedance (FAL) -----------------------------------------------------
        // Seedance 2.0 — current default tier. text/image use image_url + end_image_url.
        $seedance2Common = $seed + [
            'duration' => ['field' => 'duration', 'cast' => 'string', 'default' => 'auto'],
            'resolution' => ['field' => 'resolution', 'cast' => 'string', 'default' => '720p'],
            'generate_audio' => ['field' => 'generate_audio', 'cast' => 'bool', 'default' => true],
            'end_user_id' => ['field' => 'end_user_id', 'cast' => 'string'],
        ];

        $specs[EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai',
            endpoint: EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO,
            kind: 'text',
            creditIndex: 7.5,
            promptMode: 'required',
            options: ['aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string', 'default' => '16:9']] + $seedance2Common,
        );
        $specs[EntityEnum::FAL_SEEDANCE_2_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai',
            endpoint: EntityEnum::FAL_SEEDANCE_2_IMAGE_TO_VIDEO,
            kind: 'image',
            creditIndex: 7.5,
            promptMode: 'required',
            firstFrameField: 'image_url',
            lastFrameField: 'end_image_url',
            firstFrameRequired: true,
            options: ['aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string', 'default' => 'auto']] + $seedance2Common,
        );
        $specs[EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai',
            endpoint: EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO,
            kind: 'reference',
            creditIndex: 7.5,
            promptMode: 'required',
            referenceImageField: 'image_urls',
            supportsVideoRefs: true,
            supportsAudioRefs: true,
            augmentStyle: 'seedance',
            options: ['aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string', 'default' => '16:9']] + $seedance2Common,
        );

        // Seedance 2.0 Fast — same schema as standard 2.0.
        $specs[EntityEnum::FAL_SEEDANCE_2_FAST_TEXT_TO_VIDEO] = $specs[EntityEnum::FAL_SEEDANCE_2_TEXT_TO_VIDEO]->withEndpoint(EntityEnum::FAL_SEEDANCE_2_FAST_TEXT_TO_VIDEO);
        $specs[EntityEnum::FAL_SEEDANCE_2_FAST_IMAGE_TO_VIDEO] = $specs[EntityEnum::FAL_SEEDANCE_2_IMAGE_TO_VIDEO]->withEndpoint(EntityEnum::FAL_SEEDANCE_2_FAST_IMAGE_TO_VIDEO);
        $specs[EntityEnum::FAL_SEEDANCE_2_FAST_REFERENCE_TO_VIDEO] = $specs[EntityEnum::FAL_SEEDANCE_2_REFERENCE_TO_VIDEO]->withEndpoint(EntityEnum::FAL_SEEDANCE_2_FAST_REFERENCE_TO_VIDEO);

        // Seedance v1.5 Pro — integer duration, adds generate_audio + camera_fixed.
        $seedance15Common = $seed + [
            'aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string', 'default' => '16:9'],
            'resolution' => ['field' => 'resolution', 'cast' => 'string', 'default' => '720p'],
            'duration' => ['field' => 'duration', 'cast' => 'int', 'default' => 5],
            'generate_audio' => ['field' => 'generate_audio', 'cast' => 'bool', 'default' => true],
            'camera_fixed' => ['field' => 'camera_fixed', 'cast' => 'bool'],
        ];
        $specs[EntityEnum::FAL_SEEDANCE_15_PRO_TEXT_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_SEEDANCE_15_PRO_TEXT_TO_VIDEO, kind: 'text',
            creditIndex: 7.5, promptMode: 'required', options: $seedance15Common,
        );
        $specs[EntityEnum::FAL_SEEDANCE_15_PRO_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_SEEDANCE_15_PRO_IMAGE_TO_VIDEO, kind: 'image',
            creditIndex: 7.5, promptMode: 'required',
            firstFrameField: 'image_url', lastFrameField: 'end_image_url', firstFrameRequired: true,
            options: $seedance15Common,
        );

        // Seedance v1 Pro — string duration, camera_fixed/num_frames/safety, no audio.
        $seedance1Common = $seed + [
            'aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string', 'default' => '16:9'],
            'resolution' => ['field' => 'resolution', 'cast' => 'string', 'default' => '1080p'],
            'duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5'],
            'camera_fixed' => ['field' => 'camera_fixed', 'cast' => 'bool'],
            'num_frames' => ['field' => 'num_frames', 'cast' => 'int'],
            'enable_safety_checker' => ['field' => 'enable_safety_checker', 'cast' => 'bool'],
        ];
        $specs[EntityEnum::FAL_SEEDANCE_1_PRO_TEXT_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_SEEDANCE_1_PRO_TEXT_TO_VIDEO, kind: 'text',
            creditIndex: 7.5, promptMode: 'required', options: $seedance1Common,
        );
        $specs[EntityEnum::FAL_SEEDANCE_1_PRO_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_SEEDANCE_1_PRO_IMAGE_TO_VIDEO, kind: 'image',
            creditIndex: 7.5, promptMode: 'required',
            firstFrameField: 'image_url', lastFrameField: 'end_image_url', firstFrameRequired: true,
            options: ['aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string', 'default' => 'auto']] + $seedance1Common,
        );
        // Seedance v1 Lite reference — uses reference_image_urls (no video/audio refs).
        $specs[EntityEnum::FAL_SEEDANCE_1_LITE_REFERENCE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_SEEDANCE_1_LITE_REFERENCE_TO_VIDEO, kind: 'reference',
            creditIndex: 7.5, promptMode: 'required',
            referenceImageField: 'reference_image_urls', augmentStyle: 'seedance',
            options: $seed + [
                'aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string', 'default' => 'auto'],
                'resolution' => ['field' => 'resolution', 'cast' => 'string', 'default' => '720p'],
                'duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5'],
                'camera_fixed' => ['field' => 'camera_fixed', 'cast' => 'bool'],
                'num_frames' => ['field' => 'num_frames', 'cast' => 'int'],
            ],
        );

        // --- Kling (FAL) --------------------------------------------------------
        // o3 image-to-video (standard + pro) — image_url + end_image_url, optional prompt, multi_prompt.
        $klingO3Image = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO, kind: 'image',
            creditIndex: 8.0, promptMode: 'optional',
            firstFrameField: 'image_url', lastFrameField: 'end_image_url', firstFrameRequired: true,
            supportsMultiPrompt: true,
            options: ['duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5'],
                'generate_audio' => ['field' => 'generate_audio', 'cast' => 'bool', 'default' => false]],
        );
        $specs[EntityEnum::FAL_KLING_O3_IMAGE_TO_VIDEO] = $klingO3Image;
        $specs[EntityEnum::FAL_KLING_O3_PRO_IMAGE_TO_VIDEO] = $klingO3Image->withEndpoint(EntityEnum::FAL_KLING_O3_PRO_IMAGE_TO_VIDEO);

        // o3 reference-to-video — elements + image_urls + start/end frame.
        $specs[EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_KLING_O3_REFERENCE_TO_VIDEO, kind: 'reference',
            creditIndex: 8.0, promptMode: 'optional',
            firstFrameField: 'start_image_url', lastFrameField: 'end_image_url',
            referenceImageField: 'image_urls', supportsElements: true, supportsMultiPrompt: true,
            augmentStyle: 'kling',
            options: ['duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5'],
                'aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string', 'default' => '16:9'],
                'generate_audio' => ['field' => 'generate_audio', 'cast' => 'bool', 'default' => false]],
        );

        // v3 Pro i2v — start_image_url + end_image_url, negative_prompt, cfg_scale, audio.
        $specs[EntityEnum::FAL_KLING_V3_PRO_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_KLING_V3_PRO_IMAGE_TO_VIDEO, kind: 'image',
            creditIndex: 8.0, promptMode: 'optional',
            firstFrameField: 'start_image_url', lastFrameField: 'end_image_url', firstFrameRequired: true,
            supportsElements: true, supportsMultiPrompt: true,
            options: ['duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5'],
                'generate_audio' => ['field' => 'generate_audio', 'cast' => 'bool', 'default' => true]] + $negativePrompt + $cfgScale,
        );

        // o1 i2v — dual-keyframe interpolation, prompt required (@Image1/@Image2).
        $specs[EntityEnum::FAL_KLING_O1_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_KLING_O1_IMAGE_TO_VIDEO, kind: 'image',
            creditIndex: 8.0, promptMode: 'required',
            firstFrameField: 'start_image_url', lastFrameField: 'end_image_url', firstFrameRequired: true,
            options: ['duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5']],
        );

        // v2.6 Pro i2v — start/end frame, negative_prompt, audio, voice_ids.
        $specs[EntityEnum::FAL_KLING_V26_PRO_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_KLING_V26_PRO_IMAGE_TO_VIDEO, kind: 'image',
            creditIndex: 8.0, promptMode: 'required',
            firstFrameField: 'start_image_url', lastFrameField: 'end_image_url', firstFrameRequired: true,
            options: ['duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5'],
                'generate_audio' => ['field' => 'generate_audio', 'cast' => 'bool', 'default' => true],
                'voice_ids' => ['field' => 'voice_ids', 'cast' => 'raw']] + $negativePrompt,
        );

        // v2.1 Standard i2v — image_url only, negative_prompt, cfg_scale.
        $specs[EntityEnum::FAL_KLING_V21_STD_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_KLING_V21_STD_IMAGE_TO_VIDEO, kind: 'image',
            creditIndex: 8.0, promptMode: 'required',
            firstFrameField: 'image_url', firstFrameRequired: true,
            options: ['duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5']] + $negativePrompt + $cfgScale,
        );

        // v2.1 Master t2v — aspect_ratio, negative_prompt, cfg_scale.
        $specs[EntityEnum::FAL_KLING_V21_MASTER_TEXT_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_KLING_V21_MASTER_TEXT_TO_VIDEO, kind: 'text',
            creditIndex: 8.0, promptMode: 'required',
            options: ['duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5'],
                'aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string', 'default' => '16:9']] + $negativePrompt + $cfgScale,
        );

        // v1 Standard i2v — image_url + tail_image_url, masks.
        $specs[EntityEnum::FAL_KLING_V1_STD_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_KLING_V1_STD_IMAGE_TO_VIDEO, kind: 'image',
            creditIndex: 8.0, promptMode: 'required',
            firstFrameField: 'image_url', lastFrameField: 'tail_image_url', firstFrameRequired: true,
            options: ['duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5'],
                'static_mask_url' => ['field' => 'static_mask_url', 'cast' => 'string'],
                'dynamic_masks' => ['field' => 'dynamic_masks', 'cast' => 'raw']] + $negativePrompt + $cfgScale,
        );

        // v1 Standard t2v — camera control.
        $specs[EntityEnum::FAL_KLING_V1_STD_TEXT_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_KLING_V1_STD_TEXT_TO_VIDEO, kind: 'text',
            creditIndex: 8.0, promptMode: 'required',
            options: ['duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5'],
                'aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string', 'default' => '16:9'],
                'camera_control' => ['field' => 'camera_control', 'cast' => 'string'],
                'advanced_camera_control' => ['field' => 'advanced_camera_control', 'cast' => 'raw']] + $negativePrompt + $cfgScale,
        );

        // --- Luma (FAL) ---------------------------------------------------------
        $lumaLegacyOptions = ['aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string', 'default' => '16:9'],
            'loop' => ['field' => 'loop', 'cast' => 'bool']];
        $lumaRay2Options = $lumaLegacyOptions + [
            'resolution' => ['field' => 'resolution', 'cast' => 'string', 'default' => '540p'],
            'duration' => ['field' => 'duration', 'cast' => 'string', 'default' => '5s'],
        ];
        // Legacy dream-machine (endpoint fixed from the malformed luma-ai/dream-machine).
        $specs[EntityEnum::FAL_LUMA_DREAM] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: 'fal-ai/luma-dream-machine', kind: 'text',
            creditIndex: 8.0, promptMode: 'required', options: $lumaLegacyOptions,
        );
        $specs[EntityEnum::FAL_LUMA_DREAM_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: 'fal-ai/luma-dream-machine/image-to-video', kind: 'image',
            creditIndex: 8.0, promptMode: 'required',
            firstFrameField: 'image_url', lastFrameField: 'end_image_url', firstFrameRequired: true,
            options: $lumaLegacyOptions,
        );
        $specs[EntityEnum::FAL_LUMA_RAY2_TEXT_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_LUMA_RAY2_TEXT_TO_VIDEO, kind: 'text',
            creditIndex: 8.0, promptMode: 'required', options: $lumaRay2Options,
        );
        $specs[EntityEnum::FAL_LUMA_RAY2_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_LUMA_RAY2_IMAGE_TO_VIDEO, kind: 'image',
            creditIndex: 8.0, promptMode: 'required',
            firstFrameField: 'image_url', lastFrameField: 'end_image_url', firstFrameRequired: true,
            options: $lumaRay2Options,
        );
        $specs[EntityEnum::FAL_LUMA_RAY2_FLASH_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: EntityEnum::FAL_LUMA_RAY2_FLASH_IMAGE_TO_VIDEO, kind: 'image',
            creditIndex: 8.0, promptMode: 'required',
            firstFrameField: 'image_url', lastFrameField: 'end_image_url', firstFrameRequired: false,
            options: $lumaRay2Options,
        );

        // --- Stable Video Diffusion (FAL) — image-to-video, no prompt. ----------
        $specs[EntityEnum::FAL_STABLE_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: 'fal-ai/stable-video', kind: 'image',
            creditIndex: 5.0, promptMode: 'none',
            firstFrameField: 'image_url', firstFrameRequired: true,
            options: $seed + [
                'motion_bucket_id' => ['field' => 'motion_bucket_id', 'cast' => 'int', 'default' => 127],
                'cond_aug' => ['field' => 'cond_aug', 'cast' => 'float', 'default' => 0.02],
                'fps' => ['field' => 'fps', 'cast' => 'int', 'default' => 25],
            ],
        );

        // --- AnimateDiff (FAL) — text-to-video. ---------------------------------
        $animateDiffOptions = $seed + [
            'negative_prompt' => ['field' => 'negative_prompt', 'cast' => 'string'],
            'num_frames' => ['field' => 'num_frames', 'cast' => 'int', 'default' => 16],
            'fps' => ['field' => 'fps', 'cast' => 'int', 'default' => 8],
            'video_size' => ['field' => 'video_size', 'cast' => 'raw', 'default' => 'square'],
            'motions' => ['field' => 'motions', 'cast' => 'raw'],
        ];
        $specs[EntityEnum::FAL_ANIMATEDIFF] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: 'fal-ai/fast-animatediff/turbo/text-to-video', kind: 'text',
            creditIndex: 5.0, promptMode: 'required',
            options: $animateDiffOptions + [
                'num_inference_steps' => ['field' => 'num_inference_steps', 'cast' => 'int', 'default' => 8],
                'guidance_scale' => ['field' => 'guidance_scale', 'cast' => 'float', 'default' => 2.0],
            ],
        );
        $specs[EntityEnum::FAL_ANIMATEDIFF_TEXT_TO_VIDEO] = new VideoModelSpec(
            engine: 'fal_ai', endpoint: 'fal-ai/fast-animatediff/text-to-video', kind: 'text',
            creditIndex: 5.0, promptMode: 'required',
            options: $animateDiffOptions + [
                'num_inference_steps' => ['field' => 'num_inference_steps', 'cast' => 'int', 'default' => 25],
                'guidance_scale' => ['field' => 'guidance_scale', 'cast' => 'float', 'default' => 7.5],
            ],
        );

        // --- Replicate WAN ------------------------------------------------------
        $wan21Options = $seed + [
            'negative_prompt' => ['field' => 'negative_prompt', 'cast' => 'string'],
            'aspect_ratio' => ['field' => 'aspect_ratio', 'cast' => 'string'],
            'fast_mode' => ['field' => 'fast_mode', 'cast' => 'string'],
            'sample_guide_scale' => ['field' => 'sample_guide_scale', 'cast' => 'float'],
            'sample_steps' => ['field' => 'sample_steps', 'cast' => 'int'],
            'sample_shift' => ['field' => 'sample_shift', 'cast' => 'int'],
            'lora_weights' => ['field' => 'lora_weights', 'cast' => 'string'],
            'lora_scale' => ['field' => 'lora_scale', 'cast' => 'float'],
            'disable_safety_checker' => ['field' => 'disable_safety_checker', 'cast' => 'bool'],
        ];
        $specs[EntityEnum::REPLICATE_WAN_IMAGE_TO_VIDEO] = new VideoModelSpec(
            engine: 'replicate', endpoint: EntityEnum::REPLICATE_WAN_IMAGE_TO_VIDEO, kind: 'image',
            creditIndex: 4.0, promptMode: 'required',
            firstFrameField: 'image', firstFrameRequired: true, options: $wan21Options,
        );
        $specs[EntityEnum::REPLICATE_WAN_21_I2V_720P] = $specs[EntityEnum::REPLICATE_WAN_IMAGE_TO_VIDEO]->withEndpoint(EntityEnum::REPLICATE_WAN_21_I2V_720P);

        // WAN 2.2 i2v fast — image + last_image (end frame).
        $specs[EntityEnum::REPLICATE_WAN_22_I2V_FAST] = new VideoModelSpec(
            engine: 'replicate', endpoint: EntityEnum::REPLICATE_WAN_22_I2V_FAST, kind: 'image',
            creditIndex: 4.0, promptMode: 'required',
            firstFrameField: 'image', lastFrameField: 'last_image', firstFrameRequired: true,
            options: $seed + [
                'num_frames' => ['field' => 'num_frames', 'cast' => 'int'],
                'resolution' => ['field' => 'resolution', 'cast' => 'string'],
                'frames_per_second' => ['field' => 'frames_per_second', 'cast' => 'int'],
                'interpolate_output' => ['field' => 'interpolate_output', 'cast' => 'bool'],
                'go_fast' => ['field' => 'go_fast', 'cast' => 'bool'],
                'sample_shift' => ['field' => 'sample_shift', 'cast' => 'float'],
                'disable_safety_checker' => ['field' => 'disable_safety_checker', 'cast' => 'bool'],
            ],
        );
        // WAN 2.2 i2v a14b — image optional.
        $specs[EntityEnum::REPLICATE_WAN_22_I2V_A14B] = new VideoModelSpec(
            engine: 'replicate', endpoint: EntityEnum::REPLICATE_WAN_22_I2V_A14B, kind: 'image',
            creditIndex: 4.0, promptMode: 'required',
            firstFrameField: 'image', firstFrameRequired: false,
            options: $seed + [
                'num_frames' => ['field' => 'num_frames', 'cast' => 'int'],
                'resolution' => ['field' => 'resolution', 'cast' => 'string'],
                'frames_per_second' => ['field' => 'frames_per_second', 'cast' => 'int'],
                'sample_steps' => ['field' => 'sample_steps', 'cast' => 'int'],
                'sample_shift' => ['field' => 'sample_shift', 'cast' => 'float'],
                'go_fast' => ['field' => 'go_fast', 'cast' => 'bool'],
            ],
        );
        // WAN 2.5 i2v — audio + duration.
        $specs[EntityEnum::REPLICATE_WAN_25_I2V] = new VideoModelSpec(
            engine: 'replicate', endpoint: EntityEnum::REPLICATE_WAN_25_I2V, kind: 'image',
            creditIndex: 4.0, promptMode: 'required',
            firstFrameField: 'image', firstFrameRequired: true,
            options: $seed + [
                'negative_prompt' => ['field' => 'negative_prompt', 'cast' => 'string'],
                'audio' => ['field' => 'audio', 'cast' => 'string'],
                'resolution' => ['field' => 'resolution', 'cast' => 'string'],
                'duration' => ['field' => 'duration', 'cast' => 'int'],
                'enable_prompt_expansion' => ['field' => 'enable_prompt_expansion', 'cast' => 'bool'],
            ],
        );
        // WAN 2.7 i2v — first_frame + last_frame (true first/last frame on Replicate).
        $specs[EntityEnum::REPLICATE_WAN_27_I2V] = new VideoModelSpec(
            engine: 'replicate', endpoint: EntityEnum::REPLICATE_WAN_27_I2V, kind: 'image',
            creditIndex: 4.0, promptMode: 'optional',
            firstFrameField: 'first_frame', lastFrameField: 'last_frame', firstFrameRequired: false,
            options: $seed + [
                'negative_prompt' => ['field' => 'negative_prompt', 'cast' => 'string'],
                'audio' => ['field' => 'audio', 'cast' => 'string'],
                'resolution' => ['field' => 'resolution', 'cast' => 'string'],
                'duration' => ['field' => 'duration', 'cast' => 'int'],
                'enable_prompt_expansion' => ['field' => 'enable_prompt_expansion', 'cast' => 'bool'],
            ],
        );

        return self::$specs = $specs;
    }
}
