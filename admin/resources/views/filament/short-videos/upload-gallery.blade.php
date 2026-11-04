<x-filament-panels::page>
    <x-filament::section>
        <form x-data="{
            files: [], category: '', batch: '', busy: false, selectionError: '',
            add(event) {
                if (this.batch) return;
                const selected = Array.from(event.target.files);
                event.target.value = '';
                if (this.files.length + selected.length > 100) {
                    this.selectionError = '每批最多上传 100 个文件';
                    return;
                }
                this.selectionError = '';
                this.files.push(...selected.map(file => ({ file, done: false, status: '待上传' })));
            },
            clear() {
                this.files = [];
                this.batch = '';
                this.selectionError = '';
                this.$refs.files.value = '';
            },
            async upload() {
                if (this.busy || !this.category || !this.files.length || this.files.length > 100) return;
                this.batch ||= crypto.randomUUID();
                this.busy = true;
                try {
                    for (const [order, entry] of this.files.entries()) {
                        if (entry.done) continue;
                        entry.status = '上传中';
                        const data = new FormData();
                        data.append('batch', this.batch);
                        data.append('category', this.category);
                        data.append('order', order);
                        data.append('file', entry.file);
                        try {
                            const response = await fetch(this.$el.action, {
                                method: 'POST',
                                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': this.$refs.csrf.value },
                                body: data
                            });
                            const result = await response.json();
                            entry.done = response.ok;
                            entry.status = result.message || '上传失败';
                        } catch {
                            entry.status = '上传未确认，可重试';
                        }
                    }
                } finally {
                    this.busy = false;
                }
            }
        }" action="{{ route('admin.efv-gallery.upload') }}" x-on:submit.prevent="upload" class="space-y-5">
            <input type="hidden" x-ref="csrf" value="{{ csrf_token() }}">
            <p>每批文件进入同一个 EFV 合集。视频提交后由 EFV 转码。</p>
            <div>
                <label for="gallery-category" class="block font-medium">所属分类</label>
                <x-filament::input.wrapper>
                    <x-filament::input.select id="gallery-category" x-model="category" x-bind:disabled="!!batch" required>
                        <option value="">请选择短视频分类</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }} · {{ $category->efv_section === 'chigua' ? '吃瓜' : '图集' }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </div>
            <div>
                <label for="gallery-files" class="block font-medium">图片和视频</label>
                <input id="gallery-files" type="file" x-ref="files" x-on:change="add" x-bind:disabled="!!batch"
                    accept="image/jpeg,image/png,image/webp,.mp4,.mkv,.avi,.dat,.mpg,.rmvb,.rm,.flv,.mpeg,.mov,.wmv,.vob,.asf,.ts" multiple>
                <p class="text-sm">可分批选择，最多 100 个文件。图片限 15 MB，视频限 45 MB。</p>
                <p role="alert" x-text="selectionError"></p>
            </div>
            <ol aria-live="polite" class="space-y-1">
                <template x-for="(entry, index) in files" x-bind:key="index">
                    <li><span x-text="entry.file.name"></span> · <span x-text="entry.status"></span></li>
                </template>
            </ol>
            <x-filament::button type="submit" x-bind:disabled="busy || !category || !files.length || files.length > 100 || files.every(entry => entry.done)">
                <span x-text="busy ? '正在上传' : batch ? '重试未成功文件' : '开始批量上传'"></span>
            </x-filament::button>
            <x-filament::button type="button" color="gray" x-bind:disabled="busy" x-on:click="clear">清空待上传</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
