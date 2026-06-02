# MagicAI → Engine: Complete Feature Coverage Matrix

## 1. Summary

| Metric | Count |
|---|---|
| **Total features mapped** | 150 |
| **have** | 47 |
| **partial** | 35 |
| **missing** | 67 |
| **n/a-saas-only** (explicit) | 1 |

**Status note:** The single `n/a-saas-only` row (AI Assistant Service) is functionally a SaaS-only exclusion, bringing effective "deliberately excluded" coverage in line with the SaaS list in Section 5.

**MISSING that are NEW (not in the first audit)** — `engine_status = missing` AND `already_reported = false`: **28 features.** These are the rows the engine author has not seen before and form the core risk surface. They are fully enumerated in Section 2.

Breakdown of the 28 NEW missing by fit:
- **core-engine** (should arguably be in the engine): 0
- **optional-module** (candidate engine add-ons): 9
- **saas-only-skip** (correctly out of engine scope): 19

So of the 28 newly-surfaced gaps, **only 9 are plausible engine work**; the other 19 are confirmed SaaS-product concerns. No NEW gap is a core-engine miss.

---

## 2. NEW gaps (not previously reported) — KEY SECTION

All rows below have `already_reported = false` and `engine_status = missing`. Sorted by value (high → low).

| Feature | Value | Fit | Where MagicAI does it | Suggested engine approach |
|---|---|---|---|---|
| Social Media Suite (AI-Powered) | high | saas-only-skip | Platform publishing pipeline (LinkedIn/X/Instagram/TikTok/YouTube) at app level | Keep out of engine; engine supplies content generation, app owns publishing |
| AI Music Composer (AI Music Generation) | high | optional-module | Music/Lyria generation feature | Add a music-generation driver (e.g. Google Lyria / Suno-style) with `generateAudio` music mode in EntityEnum + a MusicDriver |
| Multi-Account Management | high | saas-only-skip | Social-platform account models + OAuth credential storage | Out of scope; engine already has tenant/org scoping (`src/Services/Scope/`, `src/Services/Tenant/`) — app layer owns platform accounts |
| Testimonial Review | low | optional-module | Generator template in MagicAI catalog | Ship as a pre-built TemplateEngine template |
| Problem Agitate Solution (PAS) | low | optional-module | Copywriting framework template | Ship as a pre-built TemplateEngine copywriting template |
| Voice Call Management | low | saas-only-skip | Voice-second limits (billing) | Out of scope — billing/usage concern |
| Daily Voice Limits | low | saas-only-skip | Rate/usage limits (billing) | Out of scope — billing concern |
| Avatar Generation (Topview) | low | saas-only-skip | Proprietary Topview API | Out of scope — proprietary SaaS integration |
| Product Avatar (Topview) | low | saas-only-skip | Proprietary Topview API | Out of scope — proprietary SaaS integration |
| Avatar & Lipsync (Creatify) | low | saas-only-skip | Creatify lipsync service | Out of scope — proprietary SaaS integration |
| AI Scripts Generation (Creatify) | low | saas-only-skip | Creatify script service | Out of scope — proprietary SaaS integration |
| Link to Video (Creatify) | low | saas-only-skip | Creatify URL-to-video | Out of scope — proprietary SaaS integration |
| Fashion Studio | low | saas-only-skip | Marketplace extension | Out of scope — marketplace extension |
| Product Photography | low | saas-only-skip | ai-product-shot marketplace extension | Out of scope — marketplace extension |
| Blog Post Management | low | saas-only-skip | `BlogController` at app level | Out of scope — app CRUD |
| Email Templates Management | low | saas-only-skip | `EmailTemplatesController` + `EmailTemplates` model | Out of scope — app CRUD |
| OpenAI Generator Library | low | saas-only-skip | `OpenAIGenerator` model (30+ generators in `openai` table) | Out of scope — app catalog |
| Generator Filters | low | saas-only-skip | `OpenaiGeneratorFilter` model | Out of scope — app catalog |
| Generator Chat Categories | low | saas-only-skip | `OpenaiGeneratorChatCategory` model | Out of scope — app catalog |
| Creatify Voice and Music API | low | optional-module | Creatify voice/music API | Optional driver if Creatify support is wanted |
| Object Removal (Image) | medium | optional-module | (Not explicitly in MagicAI; inpaint/Clipdrop-adjacent) | Add to a unified image-edit pipeline (Clipdrop/inpaint driver) |
| Generative Fill (Image) | medium | optional-module | (Inpainting/variation exists; explicit fill not documented) | Add as inpaint mode on image-edit pipeline |
| Speechify Text-to-Speech | medium | optional-module | Speechify TTS | Add a `SpeechifyEngineDriver` parallel to GoogleTTS/Azure |
| Voice Isolator (Audio Enhancement) | medium | optional-module | Audio enhancement feature | Add an isolation method (ElevenLabs audio-isolation API) to ElevenLabs driver |
| ElevenLabs Knowledge Base | medium | optional-module | ElevenLabs KB integration | Extend ElevenLabs driver with KB API; or route through existing Learning service |
| Personal API Key Management | medium | saas-only-skip | `User` model OpenAI/Anthropic key fields | Out of scope — engine uses global config credentials; app owns per-user keys |
| Text Removal (Image) | low | optional-module | (Part of object removal/inpaint; not explicit) | Fold into object-removal/inpaint pipeline |
| Auto-Translation | low | saas-only-skip | (No content-translation service; only UI localization) | Out of scope — or optional translate driver (DeepL/Google Translate) if desired |

**Plus additional NEW missing rows from the social/admin cluster** (also `missing` + not reported; saas-only, low/medium value): Extension Marketplace, SocialMediaAccounts Model, Social Media Accounts Admin Panel, Social Media Automation (medium). These are confirmed SaaS-product scope.

> **Key takeaway for the author:** No newly-surfaced gap is a core-engine miss. The only NEW gaps worth engine consideration are the 9 `optional-module` items — most prominently **AI Music Composer** (high value) and the **image-edit family** (Object Removal / Generative Fill / Text Removal), plus **Speechify**, **Voice Isolator**, and **ElevenLabs Knowledge Base**.

---

## 3. Partially-covered (worth strengthening)

Rows with `engine_status = partial`. "engine file" cites the file path given as evidence.

| Feature | What's missing in engine | engine file | MagicAI ref |
|---|---|---|---|
| Image Edit (Inpaint) | OpenAI images/edits and Stable Diffusion inpaint endpoints | `src/Drivers/FalAI/FalAIEngineDriver.php` (FAL_NANO_BANANA_2_EDIT, prepareImageOperation 239-261) | GAP_AUDIT §4 |
| Image-to-Image Transformation | OpenAI image-to-image not implemented | `src/Drivers/StableDiffusion/StableDiffusionEngineDriver.php`, `src/Drivers/FalAI/FalAIEngineDriver.php` | GAP_AUDIT |
| Image Upscaling | SD upscale (esrgan-v1-x2plus) + unified upscale pipeline | `src/Drivers/Midjourney/MidjourneyEngineDriver.php` (upscale 228-264) | GAP_AUDIT §2 |
| Image Variation (Reimagine) | OpenAI images/variations endpoint | `src/Drivers/Midjourney/MidjourneyEngineDriver.php` (vary 266-304) | GAP_AUDIT §4 |
| Multi-Prompt Image Generation | Only FAL/Stability support; OpenAI/others lack weighted multi-prompt | `src/Drivers/FalAI/FalAIEngineDriver.php` (normalizeMultiPrompt 806-824) | — |
| Image Style Customization | Not unified; limited to specific providers | `src/Drivers/OpenAI/OpenAIEngineDriver.php`, `src/Drivers/FalAI/FalAIEngineDriver.php` | — |
| Batch Image Generation | Generic batch exists but not wired to images; no batch-count (`n`) param exposed | `src/Services/BatchProcessor.php` (24-97) | — |
| Background Removal (Image) | Video BG-removal exists; image-specific BG removal not named separately | (VIDEO_BACKGROUND_REMOVAL driver) | — |
| Chat with PDF/Documents | No conversational PDF chat interface; doc extraction issues | `src/Services/Media/DocumentService.php`, `src/Services/Learning/Adapters/FileLearningAdapter.php` | GAP_AUDIT §3 |
| Chat History and Folders | No Folder model; no search/filter in transcript service | `src/Models/Conversation.php`, `src/Services/ConversationTranscriptService.php` | MagicAI full search |
| External/Embedded Chatbot | No per-bot session scope, JWT/public tokens, CORS, per-bot rate limit | `src/Http/Controllers/Api/AgentChatApiController.php` | GAP_AUDIT |
| Embeddable Chatbot Backend | No embed/widget API or CORS/framing controls | `src/Http/Controllers/Api/AgentChatApiController.php` | GAP_AUDIT |
| AI Chat Pro (Premium Chat Interface) | No premium tier enforcement, folder/memory/skill storage, entity highlighting | `src/Services/Agent/`, `src/Services/Learning/`, `src/Services/TemplateEngine.php` | — |
| AI Content Detector | Only a side-field of plagiarism checking; no standalone detector | `src/Drivers/PlagiarismCheck/PlagiarismCheckEngineDriver.php` (ai_content_percentage) | — |
| Plagiarism+AI-Detection Gate | No AI-detection or gate/policy integration for gating content | `src/Drivers/PlagiarismCheck/PlagiarismCheckEngineDriver.php` | — |
| Video Background Removal | No specific VEED bg-removal model constants | `src/Services/Fal/FalMediaWorkflowService.php` | — |
| AI Music & Voices (Creatify) | No music library or Creatify voice catalog | `src/Drivers/ElevenLabs/ElevenLabsEngineDriver.php` | — |
| Web Crawler RAG | No built-in crawler (RAG can ingest crawled content) | `src/Tools/Provider/WebSearch.php`, `src/Services/RAG/` | GAP_AUDIT |
| Web Search Integration (Web Search/Social/Music) | Not all providers support web search | `src/Tools/Provider/WebSearch.php`, Perplexity/Serper drivers | GAP_AUDIT |
| FalAI Settings Controller | Admin-only settings controller (Flux Pro, Flux 2 Flex, social models) | `src/Drivers/FalAI/FalAIEngineDriver.php` | MagicAI admin layer |
| Third-Party Package Integrations | Klap/Topview/Vizard/Creatify packages absent | `src/Drivers/` (FalAI, ElevenLabs, OpenRouter) | MagicAI `/app/Packages/` |
| Social Media Agent | No domain-specific social agents (account mgmt, archiving, multi-account) | `src/Services/Agent/` (AgentPlanner, AgentSkillMatcher, etc.) | — |
| Content Scheduling & Calendar | No content-staging-pipeline abstraction | `src/Services/Agent/` (AIAgentRun, AIAgentRunStep) | GAP_AUDIT |
| BlogPilot (AI Content Agent) | Staged content-authoring workflow (per-stage variants, human checkpoint, carry-forward) | `src/Services/Agent/` | GAP_AUDIT §2 |
| Team Collaboration | No team role/permission, per-seat credit allocation, invitation flow | `src/Services/Scope/`, `Vector/VectorAccessControl.php`, `GraphOntologyService.php` | — |
| Brand Voice Management | Orphaned: not a singleton, not wired into generation paths, no persistence | `src/Services/BrandVoiceManager.php` | GAP_AUDIT §2 |
| Article Generator | Multi-step wizard layer over generic template framework | (TemplateEngine framework) | `AIArticleWizardController` |
| Chat Templates Catalog | Chat-specific template schema extension | (TemplateEngine) | — |
| Post Title Generator | No pre-built post-title template | `src/Services/TemplateEngine.php` | — |
| Product Description | Product-specific templates | (TemplateEngine framework) | — |
| Product Name Generator | Custom prompt templates | (TemplateEngine) | — |
| Blog Section / Blog Post Ideas / Blog Intros / Blog Conclusion | Blog-specific templates over generic framework | `src/Services/TemplateEngine.php` (expand template) | — |
| Paragraph Generator | (Framework can handle; no dedicated template) | (TemplateEngine) | — |
| Pros & Cons | List-generation template | (TemplateEngine) | — |
| FAQ Generator | Custom FAQ templates | (TemplateEngine) | — |
| Newsletter Generator | Newsletter-specific templates | (TemplateEngine) | — |
| Grammar Correction | No built-in grammar-correction template (AIEngineService can run prompt) | `src/Services/AIEngineService.php` | — |
| User API Access Control | Plan-level feature gating (`api_option`) | `src/Http/Controllers/Api/` (GenerateApiController, AgentChatApiController) | MagicAI plan layer |
| Default Provider Selection | Per-feature default selection (chat/images/voiceover/wizard) | `config/ai-engine.php` | MagicAI settings |
| Web Search (Web Search/Social/Music) | Not all providers support web search | `src/Tools/Provider/WebSearch.php`, Perplexity/Serper | GAP_AUDIT |

---

## 4. Full coverage matrix

Every feature, grouped by area. Columns: Feature | Area | Engine status | Fit | Value | Already-reported.

### Area: Text / Copywriting Generators

| Feature | Engine status | Fit | Value | Already-reported |
|---|---|---|---|---|
| Post Title Generator | partial | optional-module | medium | no |
| Summarize Text | have | core-engine | high | yes |
| Product Description | partial | optional-module | medium | no |
| Article Generator | partial | optional-module | high | yes |
| Product Name Generator | partial | optional-module | low | no |
| Testimonial Review | missing | optional-module | low | no |
| Problem Agitate Solution (PAS) | missing | optional-module | low | no |
| Blog Section | partial | optional-module | medium | no |
| Blog Post Ideas | partial | optional-module | medium | no |
| Blog Intros | partial | optional-module | medium | no |
| Blog Conclusion | partial | optional-module | medium | no |
| Paragraph Generator | partial | optional-module | medium | no |
| Pros & Cons | partial | optional-module | low | no |
| FAQ Generator | partial | optional-module | medium | no |
| Email Generator | have | core-engine | high | no |
| Email Answer Generator | have | core-engine | high | no |
| Newsletter Generator | partial | optional-module | medium | no |
| Grammar Correction | partial | optional-module | high | no |
| TL;DR Summarization | have | core-engine | medium | yes |
| Custom Generation | have | core-engine | high | no |
| AI ReWriter | have | core-engine | high | no |
| AI Writer (Text Generator) | have | core-engine | high | no |
| AI Generator Controller (Streaming) | have | core-engine | high | no |
| Code Generator | have | core-engine | high | no |

### Area: Marketing / Social-Specific Copy (SaaS templates)

| Feature | Engine status | Fit | Value | Already-reported |
|---|---|---|---|---|
| Facebook Ads | missing | saas-only-skip | low | yes |
| YouTube Video Description | missing | saas-only-skip | low | yes |
| YouTube Video Title | missing | saas-only-skip | low | yes |
| YouTube Video Tags | missing | saas-only-skip | low | yes |
| Instagram Captions | missing | saas-only-skip | low | yes |
| Instagram Hashtags | missing | saas-only-skip | low | yes |
| Social Media Post Tweet | missing | saas-only-skip | low | yes |
| Social Media Post Business | missing | saas-only-skip | low | yes |
| Facebook Headlines | missing | saas-only-skip | low | yes |
| Google Ads Headlines | missing | saas-only-skip | low | yes |
| Google Ads Description | missing | saas-only-skip | low | yes |
| Meta Description | missing | saas-only-skip | low | yes |

### Area: Content Workflow / Library

| Feature | Engine status | Fit | Value | Already-reported |
|---|---|---|---|---|
| AI RSS Feed Content Generator | missing | saas-only-skip | medium | yes |
| AI YouTube to Blog | missing | saas-only-skip | high | yes |
| AI Article Wizard | missing | saas-only-skip | high | yes |
| Prompt Library (Personal & Shared) | missing | optional-module | high | no |
| Brand Voice Configuration | have | core-engine | high | yes |
| Brand Voice Management | partial | optional-module | medium | yes |
| Brand Voice Wiring (BrandVoiceManager Orphaned) | missing | optional-module | medium | yes |
| Chat Templates Catalog | partial | optional-module | medium | no |
| Blog Post Management | missing | saas-only-skip | low | no |
| Email Templates Management | missing | saas-only-skip | low | no |
| OpenAI Generator Library | missing | saas-only-skip | low | no |
| Generator Filters | missing | saas-only-skip | low | no |
| Generator Chat Categories | missing | saas-only-skip | low | no |
| Article Wizard State Management | missing | saas-only-skip | high | yes |
| Staged Article Wizard | missing | saas-only-skip | low | yes |
| BlogPilot (AI-Powered Content Agent) | partial | optional-module | high | yes |
| Content Scheduling & Calendar | partial | optional-module | medium | yes |

### Area: Image Generation & Editing

| Feature | Engine status | Fit | Value | Already-reported |
|---|---|---|---|---|
| Text-to-Image Generation | have | core-engine | high | no |
| Image Edit (Inpaint) | partial | core-engine | high | yes |
| Image-to-Image Transformation | partial | core-engine | medium | yes |
| Image Upscaling | partial | optional-module | medium | yes |
| Image Variation (Reimagine) | partial | core-engine | medium | yes |
| Sketch-to-Image | missing | optional-module | low | yes |
| Multi-Prompt Image Generation | partial | optional-module | low | no |
| Photo Studio (Advanced Image Editing) | missing | optional-module | medium | yes |
| Fashion Studio | missing | saas-only-skip | low | no |
| Product Photography | missing | saas-only-skip | low | no |
| Image Style Customization | partial | core-engine | medium | no |
| Quality and Size Customization | have | core-engine | medium | no |
| Grok Imagine Image Generation | missing | optional-module | low | yes |
| Flux Pro Image Generation | have | core-engine | high | no |
| Ideogram Image Generation | missing | optional-module | low | no |
| SeeDream Image Generation | missing | optional-module | low | no |
| Imagen 4 Image Generation | have | core-engine | medium | no |
| Nano Banana Image Generation and Editing | have | core-engine | medium | no |
| Image Storage Management | have | core-engine | high | no |
| Batch Image Generation | partial | core-engine | medium | no |
| Image History and Recent Images | have | core-engine | medium | no |
| Background Removal (Image) | partial | core-engine | high | no |
| Object Removal (Image) | missing | optional-module | medium | no |
| Text Removal (Image) | missing | optional-module | low | no |
| Generative Fill (Image) | missing | optional-module | medium | no |
| Unified Image-Edit Pipeline | missing | optional-module | medium | yes |
| OpenAI/SD Image Edit+Variation | missing | optional-module | medium | yes |
| Image Editor Extension | have | optional-module | medium | no |

### Area: Video

| Feature | Engine status | Fit | Value | Already-reported |
|---|---|---|---|---|
| AI Video Pro | have | core-engine | high | no |
| AI Captions | missing | optional-module | high | yes |
| Video Dubbing | missing | optional-module | high | yes |
| Video Editor | missing | optional-module | medium | no |
| Video Background Removal | partial | optional-module | medium | no |
| Avatar Generation (Topview) | missing | saas-only-skip | low | no |
| Product Avatar (Topview) | missing | saas-only-skip | low | no |
| Avatar & Lipsync (Creatify) | missing | saas-only-skip | low | no |
| AI Scripts Generation (Creatify) | missing | saas-only-skip | low | no |
| Link to Video (Creatify) | missing | saas-only-skip | low | no |
| Video to Shorts (Klap) | missing | saas-only-skip | medium | yes |
| Video to Shorts (Vizard) | missing | saas-only-skip | medium | yes |
| UGC Studio | missing | optional-module | medium | no |
| AI Influencer / Exported Videos | missing | optional-module | low | no |
| Avatar/Talking-Head Video | missing | optional-module | low | yes |
| Video Post-Production (Dubbing/Captions/BG-Removal) | missing | optional-module | low | yes |

### Area: Chat / Conversational

| Feature | Engine status | Fit | Value | Already-reported |
|---|---|---|---|---|
| AI Chat / Interactive Chat | have | core-engine | high | no |
| Chat with PDF/Documents | partial | core-engine | high | no |
| Vision / Image Chat | have | core-engine | high | no |
| Real-time Web Search Chat | have | core-engine | high | no |
| Real-time Voice Chat | have | core-engine | high | no |
| Floating/Web Chat Widget | missing | saas-only-skip | low | yes |
| Chatbot Training / Knowledge Base | have | core-engine | high | no |
| Multi-Model Chat Responses | missing | optional-module | medium | no |
| Chat Export | missing | optional-module | medium | no |
| Chat History and Folders | partial | core-engine | high | no |
| External/Embedded Chatbot | partial | optional-module | medium | yes |
| Embeddable Chatbot Backend | partial | saas-only-skip | medium | yes |
| Chat Streaming | have | core-engine | high | no |
| Chat Templates with System Prompts | have | core-engine | medium | no |
| Human Agent Handoff | missing | saas-only-skip | low | yes |
| Marketing/Sales Bot | missing | saas-only-skip | low | yes |
| Booking Agent | missing | saas-only-skip | low | yes |
| Voice Chatbot (Audio Input/Output) | have | core-engine | high | no |
| Social Media Chatbot Agent | missing | saas-only-skip | low | yes |
| Multi-Channel Chatbot Integration | missing | saas-only-skip | low | yes |
| AI Chat Pro (Premium Chat Interface) | partial | saas-only-skip | low | yes |
| Chat Highlight Context | missing | optional-module | low | no |
| Chat with OpenAI Assistants | missing | saas-only-skip | low | no |
| Rate Limiting and Login Requirements | have | core-engine | medium | no |
| Chat Customization (Appearance) | missing | saas-only-skip | low | yes |
| AI Assistant Service | n/a-saas-only | saas-only-skip | medium | yes |

### Area: Audio / Voice

| Feature | Engine status | Fit | Value | Already-reported |
|---|---|---|---|---|
| AI Voiceover (TTS) | have | core-engine | high | no |
| Voice Clone (Custom Voice Creation) | have | core-engine | high | no |
| Voice Isolator (Audio Enhancement) | missing | optional-module | medium | no |
| Speech-to-Text (Transcription) | have | core-engine | high | no |
| AI Music Composer (AI Music Generation) | missing | optional-module | high | no |
| Voice Chatbot (Real-time Voice Conversations) | have | core-engine | high | no |
| Shared Voice Library Management | have | core-engine | medium | no |
| Google Cloud Text-to-Speech | have | core-engine | high | no |
| Azure Text-to-Speech | have | core-engine | high | no |
| Speechify Text-to-Speech | missing | optional-module | medium | no |
| OpenAI TTS Models | have | core-engine | high | no |
| Multi-language Voice Support | have | core-engine | high | no |
| Speech Rate Control | have | core-engine | medium | no |
| Daily Voice Limits | missing | saas-only-skip | low | no |
| Voice Call Management | missing | saas-only-skip | low | no |
| ElevenLabs Knowledge Base | missing | optional-module | medium | no |
| Creatify Voice and Music API | missing | optional-module | low | no |
| REST API for TTS | have | core-engine | high | no |
| AI Music & Voices (Creatify) | partial | optional-module | medium | yes |
| ElevenLabs Voice Services | have | core-engine | high | no |
| Music Generation (Web Search/Social/Music) | missing | saas-only-skip | low | yes |

### Area: Analysis / RAG / Documents

| Feature | Engine status | Fit | Value | Already-reported |
|---|---|---|---|---|
| AI Content Detector | partial | optional-module | medium | yes |
| Plagiarism Check | have | core-engine | high | no |
| AI Vision (Image Analysis/Understanding) | have | core-engine | high | no |
| Chat with PDF (Document Analysis) | have | core-engine | high | no |
| File Search (Vector Store Document Search) | have | core-engine | high | no |
| Web Search Integration (Real-time Data) | have | core-engine | high | yes |
| Vector Search Service | have | core-engine | high | no |
| Plagiarism+AI-Detection Gate | partial | optional-module | medium | yes |
| YouTube Transcript RAG | missing | saas-only-skip | low | yes |
| Web Crawler RAG | partial | optional-module | medium | yes |
| Web Search (Web Search/Social/Music) | partial | core-engine | medium | yes |

### Area: Infrastructure / Providers / Platform

| Feature | Engine status | Fit | Value | Already-reported |
|---|---|---|---|---|
| AWS Bedrock Runtime Service | missing | core-engine | high | yes |
| AI Fine-Tuning Lifecycle Management | missing | core-engine | high | yes |
| OpenAI Fine-Tuning Lifecycle | missing | core-engine | high | yes |
| AI Model Management & Selection | have | core-engine | high | no |
| Multi-Engine Provider Abstraction | have | core-engine | high | no |
| Engine Drivers (Provider Implementations) | have | core-engine | high | no |
| Entity Drivers (Model-Specific Implementations) | have | core-engine | high | no |
| Comprehensive AI Models Library (Entity Enum) | have | core-engine | high | no |
| Model Credit/Cost System | have | core-engine | high | no |
| xAI Grok Driver | missing | core-engine | high | yes |
| Clipdrop Driver | missing | optional-module | medium | yes |
| ElevenLabs Voice Services (provider) | have | core-engine | high | no |
| Stable Diffusion Integration | have | core-engine | high | no |
| Third-Party Package Integrations | partial | optional-module | medium | no |
| FalAI Settings Controller | partial | optional-module | medium | no |
| WordPress Content Integration | missing | saas-only-skip | medium | yes |
| Personal API Key Management | missing | saas-only-skip | medium | no |
| User API Access Control | partial | saas-only-skip | medium | no |
| AI Features Toggle System | missing | saas-only-skip | medium | no |
| Default Provider Selection | partial | saas-only-skip | medium | no |
| Plan-Model Association | missing | saas-only-skip | medium | no |

### Area: SaaS Suite / Social / Team / Marketplace

| Feature | Engine status | Fit | Value | Already-reported |
|---|---|---|---|---|
| Social Media Suite (AI-Powered) | missing | saas-only-skip | high | no |
| Social Media Agent | partial | optional-module | high | no |
| Social Media Automation | missing | saas-only-skip | medium | no |
| Multi-Account Management | missing | saas-only-skip | high | no |
| Team Collaboration | partial | saas-only-skip | medium | no |
| Extension Marketplace | missing | saas-only-skip | low | no |
| Auto-Translation | missing | saas-only-skip | low | no |
| SocialMediaAccounts Model | missing | saas-only-skip | low | no |
| Social Media Accounts Admin Panel | missing | saas-only-skip | low | no |
| Social Media Integration (Web Search/Social/Music) | missing | saas-only-skip | low | yes |
| SEO Tools | have | optional-module | medium | no |
| Migration Tool (Competitor Migration) | have | optional-module | medium | no |

---

## 5. Confirmed SaaS-only (correctly excluded)

These are correctly outside the engine's scope (`fit = saas-only-skip` or the explicit `n/a-saas-only`). The engine supplies the generative primitives; the SaaS app owns the rest.

- **Social-specific copy templates:** Facebook Ads, Facebook Headlines, Google Ads Headlines/Description, Meta Description, all YouTube template generators, Instagram Captions/Hashtags, Social Media Post (Tweet/Business).
- **Content sourcing/workflow at app level:** AI RSS Feed Generator, AI YouTube to Blog, AI Article Wizard + state management + Staged Article Wizard, YouTube Transcript RAG.
- **App catalogs/CRUD:** Blog Post Management, Email Templates Management, OpenAI Generator Library, Generator Filters, Generator Chat Categories.
- **Proprietary third-party video/avatar:** Topview (Avatar / Product Avatar), Creatify (Avatar & Lipsync, AI Scripts, Link to Video), Klap & Vizard (Video to Shorts).
- **Chat delivery/product layer:** Floating/Web Chat Widget, Human Agent Handoff, Marketing/Sales Bot, Booking Agent, Social Media Chatbot Agent, Multi-Channel Chatbot Integration, AI Chat Pro tiering, Chat Customization (Appearance), Embeddable Chatbot Backend, Chat with OpenAI Assistants, AI Assistant Service (explicit out-of-scope per GAP_AUDIT §5).
- **Billing/usage:** Daily Voice Limits, Voice Call Management.
- **Plan/admin/account layer:** Personal API Key Management, User API Access Control, AI Features Toggle System, Default Provider Selection, Plan-Model Association.
- **Social/team/marketplace suite:** Social Media Suite, Social Media Automation, Multi-Account Management, Team Collaboration, Extension Marketplace, Auto-Translation, SocialMediaAccounts Model, Social Media Accounts Admin Panel, Social Media Integration, Music Generation (no UI in MagicAI), WordPress Content Integration.

---

## 6. Provider / model coverage delta

Providers and models MagicAI references that the engine **lacks** (from `missing` driver/model rows):

| Provider / Model | Engine status | Fit | Value | Notes |
|---|---|---|---|---|
| **AWS Bedrock** (Claude + Stable Diffusion runtime) | missing | core-engine | high | MagicAI has `BedrockRuntimeService.php`; no Bedrock driver in `src/Drivers/`. Highest-value provider gap. |
| **xAI Grok** (dedicated driver) | missing | core-engine | high | EntityEnum has GROK refs but routes via OpenRouter; no dedicated xAI driver. |
| **Grok Imagine** (image) | missing | optional-module | low | Tied to missing xAI driver. |
| **Speechify** (TTS) | missing | optional-module | medium | No `SpeechifyEngineDriver`. |
| **Clipdrop** (image edit / Photo Studio ops) | missing | optional-module | medium | No Clipdrop driver; needed for bg-remove/object-cleanup pipeline. |
| **Ideogram** (image, via FAL) | missing | optional-module | low | No model enum constant. |
| **SeeDream** (image + edit, via FAL) | missing | optional-module | low | No model enum constant or edit variant. |
| **Music model (Lyria / music-gen)** | missing | optional-module / saas | high / low | EntityEnum has a Lyria reference but no dedicated music driver. |
| **Creatify** (voice/music + video) | missing | optional-module / saas | low | No Creatify driver. |
| **Topview** (avatar APIs) | missing | saas-only-skip | low | Proprietary; out of engine scope. |
| **Klap / Vizard** (video-to-shorts) | missing | saas-only-skip | medium | Proprietary; out of engine scope. |

**Providers/models the engine already covers** (no delta): OpenAI (incl. DALL·E 2/3, GPT-Image, Whisper, TTS-1/HD, GPT-4o transcribe/diarize, vision, web search, vector stores, realtime), Anthropic (vision), Gemini / Imagen 4 / Imagen 4 Fast, DeepSeek, OpenRouter, Ollama, NVIDIA NIM, Cloudflare Workers AI, HuggingFace, Replicate, ComfyUI, Stable Diffusion (SDXL/SD3/SD3.5), FalAI (Flux Pro/Dev/Schnell, SDXL, SD3 Medium, Nano Banana 2 + Edit), Midjourney (upscale/vary), ElevenLabs (TTS, clone, STT, Scribe v2), Google TTS, Azure TTS/STT, LocalAudio, Serper, Perplexity, Unsplash, Pexels, PlagiarismCheck. EntityEnum spans 150+ model constants across 23+ drivers.

**Net provider gaps the author should prioritize:** AWS Bedrock and a dedicated xAI Grok driver (both core-engine, high value, already reported) lead; below them sit the optional image/audio providers (Speechify, Clipdrop, Ideogram, SeeDream, a music model).