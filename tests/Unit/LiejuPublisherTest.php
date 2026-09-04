<?php

namespace Tests\Unit;

use App\Exceptions\LiejuRemoteResultUncertainException;
use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use App\Services\GeoFlow\LiejuPublisher;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class LiejuPublisherTest extends TestCase
{
    public function test_it_publishes_plain_text_and_accepts_a_detail_url(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://www.lieju.test/city.php?post=239' => Http::response('<a href="https://post.lieju.test/116/239">焦作</a>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            'https://post.lieju.test/116/239' => Http::response('<form><input name="fid" value="239"><input name="postdb[zone_id]" value="7"></form>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            'https://post.lieju.test/116/239?action=postnew' => Http::response('发布成功 <a href="https://post.lieju.test/116/239/987654">详情</a>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $result = app(LiejuPublisher::class)->publish($this->distribution(), [
            'article' => ['title' => '标题', 'content' => "# 标题\n\n正文 **文本**"],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('987654', $result['remote_id']);
        $this->assertSame('https://post.lieju.test/116/239/987654', $result['remote_url']);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://post.lieju.test/116/239?action=postnew'
            && str_contains((string) $request->body(), '正文'));
    }

    public function test_server_error_is_uncertain(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://www.lieju.test/city.php?post=239' => Http::response('<a href="https://post.lieju.test/116/239">焦作</a>'),
            'https://post.lieju.test/116/239' => Http::response('<form><input name="fid" value="239"></form>'),
            'https://post.lieju.test/116/239?action=postnew' => Http::response('upstream error', 503),
        ]);

        $this->expectException(LiejuRemoteResultUncertainException::class);
        app(LiejuPublisher::class)->publish($this->distribution(), [
            'article' => ['title' => '标题', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);
    }

    public function test_success_page_without_a_detail_id_is_uncertain(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://www.lieju.test/city.php?post=239' => Http::response('<a href="https://post.lieju.test/116/239">焦作</a>'),
            'https://post.lieju.test/116/239' => Http::response('<form><input name="fid" value="239"></form>'),
            'https://post.lieju.test/116/239?action=postnew' => Http::response('发布成功 <a href="https://post.lieju.test/116/239">返回投稿</a>'),
        ]);

        $this->expectException(LiejuRemoteResultUncertainException::class);
        app(LiejuPublisher::class)->publish($this->distribution(), [
            'article' => ['title' => '标题', 'content' => '正文'],
            'assets' => ['images' => []],
        ]);
    }

    public function test_image_over_one_mb_is_rejected_before_post(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://www.lieju.test/city.php?post=239' => Http::response('<a href="https://post.lieju.test/116/239">焦作</a>'),
            'https://post.lieju.test/116/239' => Http::response('<form><input name="fid" value="239"></form>'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('1 MB');
        app(LiejuPublisher::class)->publish($this->distribution(), [
            'article' => ['title' => '标题', 'content' => '正文'],
            'assets' => ['images' => [['content_base64' => base64_encode(str_repeat('x', 1048577)), 'filename' => 'large.jpg']]],
        ]);
    }

    private function distribution(): ArticleDistribution
    {
        $channel = new DistributionChannel([
            'endpoint_url' => 'https://www.lieju.test',
            'channel_type' => 'lieju',
            'channel_config' => ['lieju_city' => '焦作', 'lieju_post_id' => '239'],
        ]);
        $channel->setRelation('activeSecret', null);
        $distribution = new ArticleDistribution(['article_id' => 1, 'distribution_channel_id' => 1, 'idempotency_key' => 'lieju-test']);
        $distribution->setRelation('channel', $channel);

        return $distribution;
    }
}
