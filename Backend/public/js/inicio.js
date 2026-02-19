        setTimeout(() => {
            document.querySelector('.intro-screen').classList.add('fade-out');
            setTimeout(() => {
                document.querySelector('.intro-screen').style.display = 'none';
                document.querySelector('.main-screen').classList.remove('hidden');
            }, 800);
        }, 2500);