<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Account SiMAPA</title>
</head>

<body style="margin: 0; padding: 0; font-family: Arial, Helvetica, sans-serif; background-color: #f4f4f4;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%"
        style="max-width: 600px; background-color: #ffffff; border: 1px solid #e0e0e0; margin: 20px auto;">
        <!-- Header dengan Logo -->
        <tr>
            <td style="padding: 10px; text-align: center; background-color: #003366;">
                <img src="{{ asset('assets/images/logo-sm-white.png') }}" alt="" style="width: 50px;">
                <p style="font-size:12px; color:#ffffff;"><b>AVIDPEDIA PUBLISHER</b><br>

                    +62 851-5842-2426 | Avidpedia@gmail.com</p>
            </td>
        </tr>
        <!-- Konten Utama -->
        <tr>
            <td style="padding: 30px;">
                <h1 style="color: #333333; font-size: 24px; margin: 0 0 20px; text-align: center;">Selamat Datang di
                    SiMAPA!</h1>
                <p style="color: #555555; font-size: 16px; line-height: 1.5; margin: 0 0 20px;">
                    Akun Anda telah berhasil dibuat di sistem SiMAPA. Berikut adalah detail akun Anda:
                </p>
                <table border="0" cellpadding="0" cellspacing="0" style="width: 100%; margin-bottom: 20px;">
                    <tr>
                        <td style="color: #555555; font-size: 16px; padding: 5px 0;"><strong>Username:</strong></td>
                        <td style="color: #555555; font-size: 16px; padding: 5px 0;">{{ $mailData['name'] }}</td>
                    </tr>
                    <tr>
                        <td style="color: #555555; font-size: 16px; padding: 5px 0;"><strong>Email:</strong></td>
                        <td style="color: #555555; font-size: 16px; padding: 5px 0;">{{ $mailData['email'] }}</td>
                    </tr>
                    <tr>
                        <td style="color: #555555; font-size: 16px; padding: 5px 0;"><strong>Password:</strong></td>
                        <td style="color: #555555; font-size: 16px; padding: 5px 0;">{{ $mailData['password'] }}</td>
                    </tr>
                </table>
                <p style="color: #555555; font-size: 16px; line-height: 1.5; margin: 0 0 20px;">
                    Silakan login ke sistem menggunakan kredensial di atas.
                </p>
                <!-- Tombol Login -->
                <p style="text-align: center; margin: 20px 0;">
                    @if ($mailData['force_password_change'] === '0')
                        <a href="{{ url('/') }}"
                            style="display: inline-block; padding: 12px 24px; background-color: #003366; color: #ffffff; text-decoration: none; font-size: 16px; border-radius: 5px;">Login
                            ke SiMAPA</a>
                    @else
                        <a href="{{ url('/profile') }}"
                            style="display: inline-block; padding: 12px 24px; background-color: #003366; color: #ffffff; text-decoration: none; font-size: 16px; border-radius: 5px;">Login
                            ke SiMAPA</a>
                    @endif
                </p>
            </td>
        </tr>
        <!-- Footer -->
        <tr>
            <td style="padding: 20px; text-align: center; background-color: #f8f8f8; color: #777777; font-size: 14px;">
                <p style="margin: 0;">© {{ date('Y') }} SiMAPA. All rights reserved.</p>
                <p style="margin: 5px 0;">Hubungi kami di <a href="mailto:admin@avidpedia.com"
                        style="color: #007bff; text-decoration: none;">admin@avidpedia.com</a></p>
            </td>
        </tr>
    </table>
</body>

</html>
