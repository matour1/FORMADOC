<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpenRouter;

use App\Services\OpenRouter\ModelRouter;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests du routeur de modèles OpenRouter (Phase 2 — SaaS).
 *
 * - sélection par type de tâche (modèle préféré + fallbacks)
 * - repli sur le plan gratuit si le plan demandé n'est pas configuré
 * - exception sur type de tâche inconnu
 */
class ModelRouterTest extends TestCase
{
    private ModelRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new ModelRouter;
    }

    public function test_selection_chat_text_default(): void
    {
        $selection = $this->router->select('chat_text', 'default');

        $this->assertSame('chat_text', $selection['task_type']);
        $this->assertSame('default', $selection['plan']);
        $this->assertSame('deepseek/deepseek-chat', $selection['model']);
        $this->assertContains('meta-llama/llama-3.1-8b-instruct', $selection['fallbacks']);
        $this->assertCount(2, $selection['all_candidates']);
    }

    public function test_selection_document_full_format_pro(): void
    {
        $selection = $this->router->select('document_full_format', 'pro');

        $this->assertSame('anthropic/claude-3-opus', $selection['model']);
        $this->assertContains('anthropic/claude-3.5-sonnet', $selection['fallbacks']);
    }

    public function test_selection_repli_sur_plan_default(): void
    {
        // Le plan "premium" n'est pas configuré pour document_full_format
        // dans la config → repli sur le plan gratuit
        $selection = $this->router->select('document_full_format', 'premium');

        $this->assertSame('anthropic/claude-3.5-sonnet', $selection['model']);
    }

    public function test_selection_image_generation_plan_premium(): void
    {
        $selection = $this->router->select('image_generation', 'premium');

        $this->assertSame('openai/gpt-image-1', $selection['model']);
    }

    public function test_type_de_tache_inconnu_throw(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->router->select('tache_inexistante', 'default');
    }

    public function test_candidates_for_dedupe(): void
    {
        $candidates = $this->router->candidatesFor('chat_text');

        // Sans doublon, et contient les deux modèles
        $this->assertSame($candidates, array_values(array_unique($candidates)));
        $this->assertContains('deepseek/deepseek-chat', $candidates);
        $this->assertContains('anthropic/claude-3.5-sonnet', $candidates);
    }
}
