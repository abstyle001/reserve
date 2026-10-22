<?php

namespace Tests\Feature;

use App\Models\Hotel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HotelImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    /** @test 新增酒店时可同时上传多张图片，img 存 JSON 数组 */
    public function store_with_multiple_images()
    {
        Storage::fake('public');

        $response = $this->post(route('hotels.store'), [
            'name'   => '测试酒店',
            'status' => 1,
            'images' => [
                UploadedFile::fake()->create('a.jpg', 10, 'image/jpeg'),
                UploadedFile::fake()->create('b.png', 10, 'image/jpeg'),
            ],
        ]);

        $response->assertRedirect(route('hotels.index'));

        $hotel = Hotel::firstOrFail();
        $this->assertCount(2, $hotel->img);
        foreach ($hotel->img as $path) {
            $this->assertStringStartsWith('hotels/', $path);
            Storage::disk('public')->assertExists($path);
        }
    }

    /** @test 不传图片也能正常创建 */
    public function store_without_images()
    {
        Storage::fake('public');

        $response = $this->post(route('hotels.store'), [
            'name'   => '无图酒店',
            'status' => 1,
        ]);

        $response->assertRedirect(route('hotels.index'));
        $this->assertSame([], Hotel::firstOrFail()->img);
    }

    /** @test 非图片文件被校验拦截 */
    public function store_rejects_non_image()
    {
        Storage::fake('public');

        $response = $this->post(route('hotels.store'), [
            'name'   => '测试酒店',
            'status' => 1,
            'images' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')],
        ]);

        $response->assertSessionHasErrors('images.0');
        $this->assertSame(0, Hotel::count());
    }

    /** @test 编辑时可勾选删除旧图并追加新图 */
    public function update_can_remove_and_append_images()
    {
        Storage::fake('public');

        $oldPath = UploadedFile::fake()->create('old.jpg', 10, 'image/jpeg')->store('hotels', 'public');
        $keepPath = UploadedFile::fake()->create('keep.jpg', 10, 'image/jpeg')->store('hotels', 'public');
        $hotel = Hotel::create([
            'name'   => '测试酒店',
            'status' => 1,
            'img'    => [$oldPath, $keepPath],
        ]);

        $response = $this->put(route('hotels.update', $hotel), [
            'name'          => '测试酒店',
            'status'        => 1,
            'remove_images' => [$oldPath],
            'images'        => [UploadedFile::fake()->create('new.jpg', 10, 'image/jpeg')],
        ]);

        $response->assertRedirect(route('hotels.index'));

        $hotel->refresh();
        $this->assertCount(2, $hotel->img);
        $this->assertContains($keepPath, $hotel->img);
        $this->assertNotContains($oldPath, $hotel->img);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($keepPath);
    }

    /** @test 删除酒店时磁盘上的图片一并清理 */
    public function destroy_removes_image_files()
    {
        Storage::fake('public');

        $path = UploadedFile::fake()->create('a.jpg', 10, 'image/jpeg')->store('hotels', 'public');
        $hotel = Hotel::create([
            'name'   => '测试酒店',
            'status' => 1,
            'img'    => [$path],
        ]);

        $this->delete(route('hotels.destroy', $hotel))
            ->assertRedirect(route('hotels.index'));

        Storage::disk('public')->assertMissing($path);
        $this->assertSame(0, Hotel::count());
    }
}
