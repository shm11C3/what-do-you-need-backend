<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeletePostImageRequest;
use App\Http\Requests\UploadPostImageRequest;
use App\Models\PostImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PostImageController extends Controller
{
    /**
     * @param PostImage $postImage
     */
    public function __construct(PostImage $postImage)
    {
        $this->postImage = $postImage;
    }

    /**
     * imageの登録処理
     *
     * @param UploadPostImageRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function storeImage(UploadPostImageRequest $request): \Illuminate\Http\JsonResponse
    {
        $auth_id = $request->subject;
        $image_group_uuid = $request['image_group_uuid']; // 1つのpostに関連するimagesグループのuuid

        if ($image_group_uuid === null) {
            // 1枚目のimage
            // 各値を設定
            $image_group_uuid = (string) Str::uuid();
            $image_number = 1;
        } else {
            // image_group_idが存在する場合(2枚目以降の写真)
            $max_image_number = DB::table('post_images')
            ->where('image_group_uuid', $image_group_uuid)
            ->where('auth_id', $auth_id)
            ->max('image_number');

            $image_number = $max_image_number + 1;

            // image_numberがimage_number_limit以上になること、
            // image_group_uuidが存在(2枚目以降のimage)している状態でimage_numberが1になることは
            // 仕様上ないため400エラーを返す
            if ($image_number > $this->postImage->image_number_limit || $image_number === 1) {
                abort(400);
            }
        }

        $uuid = (string) Str::uuid();

        try {
            DB::beginTransaction();

            DB::table('post_images')->insert([
                'uuid' => $uuid,
                'image_group_uuid' => $image_group_uuid,
                'auth_id' => $auth_id,
                'image_number' => $image_number,
            ]);

            $uploaded_path = $this->postImage->uploadImageToS3($request['image'], $uuid);

            DB::commit();
        } catch (\Throwable $e) {
            Log::error($e);
            DB::rollBack();
            abort(500);
        }

        return response()->json([
            'uploaded_path' => $uploaded_path,
            'uuid' => $uuid,
            'image_group_uuid' => $image_group_uuid,
            'image_number' => $image_number,
        ]);
    }

    /**
     * imageの削除処理
     *
     * @param DeletePostImageRequest $request
     * @return Illuminate\Http\JsonResponse
     */
    public function deleteImage(DeletePostImageRequest $request): \Illuminate\Http\JsonResponse
    {
        $auth_id = $request->subject;

        // 条件に合うレコードのuuidを取得
        $target_uuid = DB::table('post_images')
        ->where('auth_id', $auth_id)
        ->where('uuid', $request['uuid'])
        ->get('uuid')[0]->uuid
        ?? null;

        if ($target_uuid === null) {
            abort(403);
        }

        try{
            DB::beginTransaction();

            // DBから削除
            DB::table('post_images')
            ->where('uuid', $target_uuid)
            ->delete();

            // ファイルを削除
            $this->postImage->deleteImageFromS3([$this->postImage->getImagePath($target_uuid)]);

            DB::commit();
        } catch (\Throwable $e) {
            Log::error($e);
            DB::rollBack();
            abort(500);
        }

        return response()->json(['status' => true]);
    }
}
