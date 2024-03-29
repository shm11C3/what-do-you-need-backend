<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateUserRequest;
use App\Consts\ErrorMessage;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Auth;
use App\Models\User;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class UserProfileController extends Controller
{
    /**
     * @param User $user
     */
    public function __construct(User $user, Auth $auth)
    {
        $this->user = $user;
        $this->auth = $auth;
    }

    /**
     * 受け取ったユーザーデータをDBに挿入する
     *
     * @param CreateUserRequest $request
     * @return void
     */
    public function storeUserProfile(CreateUserRequest $request)
    {
        // auth_idがすでに登録されている場合リターン
        if($request->user){
            return response()->json(ErrorMessage::MESSAGES['user_already_exist'], 422);
        }

        // 論理削除されたユーザの場合、レコードを更新する
        try {
            if($this->user->isDeletedUser($request->subject)){
                DB::table('users')->where('auth_id', $request->subject)->update([
                    'name'       => $request['name'],
                    'username'   => $request['username'],
                    'country_id' => $request['country_id'],
                    'delete_flg' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }else{
                DB::table('users')->insert([
                    'auth_id'    => $request->subject,
                    'name'       => $request['name'],
                    'username'   => $request['username'],
                    'country_id' => $request['country_id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

        }catch (\Exception $e) {
            return response()->json([
                "status" => false,
                "message" => config('app.debug') ? $e->getMessage() : '500 : '.HttpResponse::$statusTexts[500],
                500
            ]);
        }

        Cache::forget($request->subject);

        return response()->json(["status" => true]);
    }

    /**
     * ミドルウェアで取得したユーザ情報を返す
     *
     * @param Request $request
     * @return void $user_data
     */
    public function getUserProfile(Request $request)
    {
        if(!$request->user){
            return response()->json(ErrorMessage::MESSAGES['user_does_not_exist']);
        }

        $user_data = $request->user[0];

        // `country_id`をもとに国名と国コードを追加
        $user_data->country_code = Country::COUNTRY_CODE_LIST[$request->user[0]->country_id];
        $user_data->country = Country::COUNTRY_LIST[$request->user[0]->country_id];

        return response()->json($user_data);
    }

    /**
     * Return public user data from username
     *
     * @param Request $request
     * @param string $username
     * @return response
     */
    public function getUserProfileByUsername(Request $request, $username)
    {
        if (!preg_match('/^[A-Za-z\d_]+$/', $username)) {
            abort(400);
        }

        $auth_username = $request->user[0]->username ?? null;

        // ログイン中のユーザーの場合
        if ($auth_username === $username) {
            $user_data = (object)[
                'name' => $request->user[0]->name,
                'username' => $request->user[0]->username,
                'country_id' => $request->user[0]->country_id,
                'created_at' => $request->user[0]->created_at,
            ];
        } else {
            $response = DB::table('users')->where('username', $username)->where('delete_flg', 0)
            ->get([
                'name',
                'username',
                'country_id',
                'created_at',
            ]);

            if (!isset($response[0])) {
                abort(404);
            }

            $user_data = $response[0];
        }

        // `country_id`をもとに国名と国コードを追加
        $user_data->country_code = Country::COUNTRY_CODE_LIST[$user_data->country_id];
        $user_data->country = Country::COUNTRY_LIST[$user_data->country_id];

        return response()->json($user_data);
    }

    /**
     * 受け取ったユーザーデータでDBを更新する
     *
     * @param UpdateUserRequest $request
     * @return void
     */
    public function updateUserProfile(UpdateUserRequest $request)
    {
        // ユーザーが登録されているか
        if(!$request->user){
            return response()->json(ErrorMessage::MESSAGES['user_does_not_exist']);
        }

        // 他のユーザーが同じ `username` を登録していないか
        if(!DB::table('users')->where('username', $request['username'])->where('auth_id', '!=', $request->subject)){
            return response()->json(ErrorMessage::MESSAGES['username_is_already_used'], 422);
        }

        $user_data = $this->user->mergeUpdateUserData([
            'name'       => $request['name'],
            'username'   => $request['username'],
            'country_id' => $request['country_id'],
            'updated_at' => now(),
        ]);

        if(!$user_data){
            return response()->json(["status" => true]);
        }

        // DBテーブルを更新
        try{
            DB::table('users')->where('auth_id', $request->subject)->update($user_data);
        }catch(\Exception $e){
            return response()->json([
                "status" => false,
                "message" => config('app.debug') ? $e->getMessage() : '500 : '.HttpResponse::$statusTexts[500],
                500
            ]);
        }

        return response()->json(["status" => true]);
    }

    /**
     * ユーザーを削除
     *
     * @param Request $request
     * @return void
     */
    public function deleteUserProfile(Request $request)
    {
        // ユーザーが登録されているか
        if(!$request->user){
            return response()->json(ErrorMessage::MESSAGES['user_does_not_exist']);
        }

        // usersからユーザーを論理削除
        try{
            DB::table('users')->where('auth_id', $request->subject)->update([
                'username' => null,
                'delete_flg' => 1
            ]);

            $this->auth->deleteAuth0Account($request->subject);

        }catch(\Exception $e){
            return response()->json([
                "status" => false,
                "message" => config('app.debug') ? $e->getMessage() : '500 : '.HttpResponse::$statusTexts[500],
                500
            ]);
        }

        Cache::forget($request->subject);

        return response()->json(["status" => true]);
    }

    /**
     * `username`の重複をチェックする
     *
     * @param Request $request
     * @return void
     */
    public function duplicateUsername_exists(Request $request)
    {
        if(!$request->username){
            return abort(400);
        }
        $result = (bool) count(DB::table('users')->where('username', $request->username)->limit(1)->get('username'));

        return response()->json(["result" => $result]);
    }
}
