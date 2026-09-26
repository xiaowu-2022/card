export const transferCatalog = {
    Currency: ['币种', 'Mata wang', 'Moneda'],
    Time: ['时间', 'Masa', 'Hora'],
    Transfer: ['转账', 'Pindahan', 'Transferir'],
    'Transfer sent': ['转账支出', 'Pindahan keluar', 'Transferencia enviada'],
    'Transfer received': ['转账收入', 'Pindahan diterima', 'Transferencia recibida'],
    'Send wallet balance to another account in your company, in the same currency.': [
        '向同公司的其他账号转账，仅支持同币种钱包余额。',
        'Pindahkan baki dompet ke akaun lain dalam syarikat anda, dalam mata wang yang sama.',
        'Envía saldo a otra cuenta de tu empresa en la misma moneda.',
    ],
    'Transfer completed.': ['转账成功。', 'Pindahan selesai.', 'Transferencia completada.'],
    'Sender account ID': ['转出账号 ID', 'ID akaun pengirim', 'ID de cuenta remitente'],
    'Recipient account ID': ['收款账号 ID', 'ID akaun penerima', 'ID de cuenta destinataria'],
    'Transfer reference': ['转账单号', 'Rujukan pindahan', 'Referencia de transferencia'],
    'New transfer': ['再次转账', 'Pindahan baharu', 'Nueva transferencia'],
    'Transfer amount': ['转账金额', 'Jumlah pindahan', 'Importe de transferencia'],
    'Review transfer': ['核对转账', 'Semak pindahan', 'Revisar transferencia'],
    'Confirm transfer': ['确认转账', 'Sahkan pindahan', 'Confirmar transferencia'],
    'Both accounts need active verified wallets in the same currency.': [
        '双方账号须状态正常、完成身份认证，且已启用同币种钱包。',
        'Kedua-dua akaun memerlukan dompet aktif dan disahkan dalam mata wang yang sama.',
        'Ambas cuentas necesitan billeteras activas y verificadas en la misma moneda.',
    ],
    'Enter a valid account ID and an amount within the selected currency precision.': [
        '请输入有效的账号 ID 和符合所选币种精度的金额。',
        'Masukkan ID akaun yang sah dan jumlah mengikut ketepatan mata wang dipilih.',
        'Introduce un ID válido y un importe con la precisión de la moneda seleccionada.',
    ],
    'Enter a positive transfer amount.': [
        '请输入大于零且符合所选币种精度的转账金额。',
        'Masukkan jumlah pindahan positif mengikut ketepatan mata wang dipilih.',
        'Introduce un importe positivo con la precisión de la moneda seleccionada.',
    ],
    'This transfer request was already used with different details.': [
        '此转账请求已使用，且与当前填写内容不同，请先核对原转账记录。',
        'Permintaan pindahan ini telah digunakan dengan butiran berbeza.',
        'Esta solicitud ya se utilizó con otros datos.',
    ],
    'The recipient is unavailable. Check the account ID and company.': [
        '收款账号不可用，请确认账号 ID 正确且属于同一公司，不能转给自己。',
        'Penerima tidak tersedia. Semak ID akaun dan syarikat; tidak boleh menghantar kepada diri sendiri.',
        'El destinatario no está disponible. Revisa el ID y la empresa; no puedes enviarte saldo a ti mismo.',
    ],
    'Your available balance is insufficient for this transfer.': [
        '可用余额不足，无法完成此次转账。',
        'Baki tersedia tidak mencukupi untuk pindahan ini.',
        'Tu saldo disponible es insuficiente para esta transferencia.',
    ],
    'The transfer could not be completed safely. Please try again with the same request.': [
        '转账暂未完成，请使用本次请求重试。',
        'Pindahan tidak dapat diselesaikan dengan selamat. Cuba semula dengan permintaan yang sama.',
        'No se pudo completar la transferencia. Reintenta con la misma solicitud.',
    ],
    'Transfer retry details could not be saved. Please enable browser storage.': [
        '无法保存转账重试信息，请允许浏览器存储后再试。',
        'Butiran cubaan semula tidak dapat disimpan. Sila benarkan storan pelayar.',
        'No se pudieron guardar los datos del reintento. Permite el almacenamiento del navegador.',
    ],
    'The same amount will be credited to the recipient. Check the account ID carefully; completed transfers cannot be cancelled here.':
        [
            '收款方将收到等额余额，不收取手续费。请仔细核对账号 ID；转账完成后无法在此撤销。',
            'Penerima akan menerima jumlah yang sama tanpa yuran. Semak ID akaun; pindahan selesai tidak boleh dibatalkan di sini.',
            'El destinatario recibirá el mismo importe sin comisión. Revisa el ID; las transferencias completadas no se pueden cancelar aquí.',
        ],
    'I have checked the recipient and amount and confirm this transfer.': [
        '我已核对收款账号和金额，确认转账。',
        'Saya telah menyemak penerima dan jumlah serta mengesahkan pindahan ini.',
        'He comprobado el destinatario y el importe y confirmo esta transferencia.',
    ],
    'If the result is unclear, retry this same transfer. Do not start a new request.': [
        '如结果不明确，请重试本次转账，不要另开新请求。',
        'Jika keputusan tidak jelas, cuba semula pindahan yang sama. Jangan mulakan permintaan baharu.',
        'Si el resultado no está claro, reintenta esta transferencia. No inicies otra solicitud.',
    ],
} satisfies Record<string, readonly [string, string, string]>;
