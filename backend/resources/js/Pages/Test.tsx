import axios from "axios";
import React, { useState } from "react";

const Test = () => {
    const [amount, setAmount] = useState<string>('');
    const [loading, setLoading] = useState<boolean>(false);

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        setLoading(true);

        try {
            await axios.post('/api/gash/deposit', {
                paidType: 'COPGAM09',
                amount: parseFloat(amount)
            }).then(response => {
                console.log(response.data.data);
    
                // 如果正確回傳，就開新分頁並把 HTML 寫入
                if (response.data && response.data.data && response.data.data.html) {
                    const newWindow = window.open('', '_blank');
                    newWindow.document.write(response.data.data.html);
                    newWindow.document.close();
                } else {
                    alert('後端未回傳 HTML 資料');
                }
            });
        } catch (error: any) {
            // 失敗則 alert
            const errorMessage = error.response?.data?.message;
            alert(errorMessage);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div>
            <form onSubmit={handleSubmit}>
                <div>
                    <input
                        type="number"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                        placeholder="請輸入金額"
                        min="0"
                        step="0.01"
                        disabled={loading}
                    />
                </div>
                <button
                    type="submit"
                    disabled={loading}
                >
                    {loading ? '提交中...' : '提交'}
                </button>
            </form>
        </div>
    );
};

export default Test;