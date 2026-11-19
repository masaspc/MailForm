<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Delivery;
use App\Models\Form;
use App\Models\Submission;
use App\Models\User;
use App\Services\Outbox;
use App\Services\SafeMarkup;
use App\Services\SpamProtection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private function receipt(): Submission
    {
        $form = Form::create(['slug'=>'case','name'=>'テスト','definition'=>[]]);
        return $form->submissions()->create(['token'=>'test-receipt','answers'=>['name'=>'保存しない回答'], 'snapshot'=>['retain'=>false]]);
    }

    public function test_outbox_delivers_once_and_erases_transient_content_after_delivery(): void
    {
        Storage::fake('local');
        $receipt = $this->receipt();
        $payload = ['to'=>['staff@example.test'],'cc'=>[],'bcc'=>[],'subject'=>'確認','body'=>'仮の本文'];
        Storage::disk('local')->put('attachments/sample','dummy');
        $file = $receipt->attachments()->create(['field_key'=>'file','path'=>'attachments/sample','name'=>'sample.txt','mime'=>'text/plain','size'=>5]);
        $job = $receipt->deliveries()->create(['kind'=>'admin','payload'=>$payload + ['attachments'=>[$file->id]]]);
        $this->assertStringNotContainsString('保存しない回答', DB::table('submissions')->value('answers'));
        $this->assertStringNotContainsString('staff@example.test', DB::table('deliveries')->value('payload'));
        $this->artisan('mailform:prune')->assertSuccessful();
        $this->assertSame(1, Attachment::count());
        $this->artisan('mailform:deliver')->assertSuccessful();
        $this->assertSame('skipped', app(Outbox::class)->send($job->id));
        $this->assertSame(1, $job->refresh()->attempts);
        $this->assertSame([], $job->payload);
        $this->artisan('mailform:prune')->assertSuccessful();
        $this->assertSame([], $receipt->refresh()->answers);
        $this->assertNotNull($receipt->purged_at);
        Storage::disk('local')->assertMissing('attachments/sample');
    }

    public function test_interrupted_mail_requires_individual_resolution(): void
    {
        $receipt = $this->receipt();
        $job = $receipt->deliveries()->create(['kind'=>'reply','status'=>'sending','payload'=>['body'=>'test']]);
        Delivery::whereKey($job->id)->update(['updated_at'=>now()->subHours(2)]);
        $this->artisan('mailform:deliver')->assertSuccessful();
        $this->assertSame('unknown',$job->refresh()->status);
        $this->artisan('mailform:resolve',['id'=>$job->id,'action'=>'sent'])->assertSuccessful();
        $this->assertSame([], $job->refresh()->payload);
        $this->assertSame('sent', $job->status);
    }

    public function test_reviewed_markup_and_theme_controls_are_available_to_administrators(): void
    {
        $this->assertSame('<p>案内<br><strong>強調</strong></p>', (string) SafeMarkup::render('<p>案内<br><strong>強調</strong></p>'));
        $this->assertStringContainsString('legacy-notice', (string) SafeMarkup::render('<textarea readonly>お知らせ</textarea>'));
        $viewer = User::factory()->create(['role'=>'viewer']);
        $this->actingAs($viewer)->get('/admin/themes')->assertForbidden();
        $user = User::factory()->create(['role'=>'admin']);
        $this->actingAs($user)->post('/admin/themes',['slug'=>'support','name'=>'サポート','layout'=>'gray','header'=>'<strong>受付</strong>'])->assertSessionHasNoErrors();
        $this->get('/admin/themes/support/edit')->assertOk()->assertSee('サポート');
        $response = $this->get('/admin/fields');
        $response->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_recaptcha_checks_hostname_score_and_action(): void
    {
        config(['mailform.recaptcha_site_key'=>'site','mailform.recaptcha_secret'=>'secret','app.url'=>'https://example.test']);
        Http::preventStrayRequests();
        Http::fakeSequence('www.google.com/*')
            ->push(['success'=>true,'action'=>'easy_mail_send','hostname'=>'example.test','score'=>0.9])
            ->push(['success'=>true,'action'=>'another_form','hostname'=>'example.test','score'=>0.9]);
        $form = new Form(['definition'=>['settings'=>['recaptcha_enabled'=>1]]]);
        $request = Request::create('/send','POST',['g-recaptcha-token'=>'test-token']);
        app(SpamProtection::class)->verify($request,$form);
        $this->expectException(HttpException::class);
        app(SpamProtection::class)->verify($request,$form);
    }
}
