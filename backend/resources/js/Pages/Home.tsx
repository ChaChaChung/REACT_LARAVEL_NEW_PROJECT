import axios from "axios";
import React from "react";

const Home = () => {
    function deposit() {
        axios.post('/api/gash/deposit', {
            paidType: 'COPGAM09',
            amount: 100
        }).then(response => {
            console.log(response);
            var form = document.createElement('form');
    
            form.method = 'POST';
            form.action = response.data.data.action_url;
            form.target = '_blank';

            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'data';
            input.value = response.data.data.form_data;

            form.appendChild(input);

            document.body.appendChild(form);

            form.submit();

            setTimeout(function() {
                document.body.removeChild(form);
            }, 100);
        });
    }

    return (
        <>
            <>this is home page</>
            <button
                onClick={() => {
                    deposit();
                }}
            >
                GASH 儲值
            </button>
        </>
    )
}
export default Home