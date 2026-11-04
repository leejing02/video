<?php

use App\Http\Controllers\EfvGalleryUploadController;
use Illuminate\Support\Facades\Route;

Route::post('/admin/efv-gallery/upload', EfvGalleryUploadController::class)
    ->middleware('auth:admin')->name('admin.efv-gallery.upload');

Route::post('/admin/efv-gallery/chunk', [EfvGalleryUploadController::class, 'chunk'])
    ->middleware('auth:admin')->name('admin.efv-gallery.chunk');

Route::get('/', fn () => redirect('/admin'));
