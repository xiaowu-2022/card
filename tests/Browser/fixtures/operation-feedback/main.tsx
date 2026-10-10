import React, { useState } from 'react';
import { createRoot } from 'react-dom/client';
import '@/../css/app.css';
import { configureClientLocale } from '@/i18n';
import { OperationResultHost } from '@/components/admin/OperationResultHost';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { showOperationResult } from '@/components/admin/operation-result';
import { Dialog, DialogContent, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
configureClientLocale('zh-CN', 'UTC');
function Fixture() {
    const [open, setOpen] = useState(false);
    const [failure, setFailure] = useState('');
    const [draft, setDraft] = useState('保留这段草稿');
    function inertiaFailure() {
        const errors = { name: 'The name field is required.' };
        document.dispatchEvent(
            new CustomEvent('inertia:error', { detail: { errors }, cancelable: true }),
        );
        setFailure(errors.name);
    }
    return (
        <>
            <main className="p-5 space-y-4">
                <h1>操作提示离线验收</h1>
                <p>仅使用合成数据，没有业务请求或数据库写入。</p>
                <div style={{ height: 1200 }} />
                <Button onClick={() => showOperationResult('success', 'Saved successfully.')}>
                    底部保存成功
                </Button>
                <Button onClick={() => setOpen(true)}>打开配置抽屉</Button>
                <Button
                    onClick={() =>
                        document.dispatchEvent(
                            new CustomEvent('inertia:networkError', {
                                detail: { error: new Error('offline') },
                                cancelable: true,
                            }),
                        )
                    }
                >
                    模拟网络失败
                </Button>
            </main>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="right-0 left-auto top-0 h-dvh max-h-dvh translate-x-0 translate-y-0 rounded-none">
                    <DialogTitle>公司配置验收</DialogTitle>
                    <DialogDescription>关闭提示后保留草稿和滚动位置。</DialogDescription>
                    <input
                        aria-label="配置草稿"
                        value={draft}
                        onChange={(event) => setDraft(event.target.value)}
                    />
                    <div style={{ height: 1100 }} />
                    <Button onClick={() => showOperationResult('success', 'Saved successfully.')}>
                        保存配置
                    </Button>
                    <Button onClick={inertiaFailure}>模拟校验失败</Button>
                    <Button
                        onClick={() => {
                            setOpen(false);
                            showOperationResult('success', 'Saved successfully.');
                        }}
                    >
                        保存并关闭编辑器
                    </Button>
                    {failure && <OperationFeedback>{failure}</OperationFeedback>}
                </DialogContent>
            </Dialog>
            <OperationResultHost />
        </>
    );
}
createRoot(document.getElementById('root')!).render(<Fixture />);
