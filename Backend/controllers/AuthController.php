<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';

class AuthController
{
    private $connection;

    public function __construct($db)
    {
        $this->connection = $db;
    }

    public function login($emailOrUser, $password)
    {
        if (empty($password)) {
            $this->setError("No has digitado la contraseña", "danger");
            return $this->redirectToLogin();
        }

        $loginInput = mysqli_real_escape_string($this->connection, $_POST['login']);

        $query = "SELECT * FROM duttyfree_usuarios WHERE email = '$loginInput' OR usuario = '$loginInput'";
        $resultado = mysqli_query($this->connection, $query);

        if (mysqli_num_rows($resultado) === 0) {
            $this->setError("No hay una cuenta asociada al email o usuario ingresado", "warning");
            return $this->redirectToLogin();
        }

        if (mysqli_num_rows($resultado) > 1) {
            $this->setError("ERROR! Existen múltiples cuentas con el mismo email o usuario", "danger");
            return $this->redirectToLogin();
        }

        $usuario = mysqli_fetch_assoc($resultado);
        $passwordHash = $usuario['password_hash'];

        if ($usuario['auth_correo'] == 1 && password_verify($password, $passwordHash)) {
            unset($usuario['password_hash']);

            $_SESSION['auth'] = [
                'isLogged' => true,
                'userInfo' => [
                    'id'     => $usuario['id'],
                    'nombre' => $usuario['nombre'],
                    'role'   => $usuario['rol']
                ]
            ];

            return redirect()->route('index');
            exit;
        }

        if ($usuario['auth_correo'] == 0) {
            $_SESSION['error'] = [
                'mensaje' => "ERROR! No has autenticado el correo.<br><a href='" . BASE_URL . "/pages/resend-email.php'>¿No te ha llegado el correo?</a>",
                'tipo' => "danger",
                'email' => $usuario['email']
            ];
            return $this->redirectToLogin();
        }

        $failedAttempts = $usuario['failed_attemps'] + 1;
        $updateQuery = "UPDATE duttyfree_usuarios SET failed_attemps = ? WHERE email = ? OR usuario = ?";
        $stmt = $this->connection->prepare($updateQuery);
        $stmt->bind_param("iss", $failedAttempts, $usuario['email'], $usuario['usuario']);
        $stmt->execute();
        $stmt->close();

        $this->setError("La contraseña es incorrecta. Intento: $failedAttempts", "danger");
        return $this->redirectToLogin();
    }

public function logout()
{
    Auth::logout();
    return redirect()->route('login')->with('message', 'Has cerrado sesión correctamente');
}


    private function setError($mensaje, $tipo)
    {
        $_SESSION['error'] = [
            'mensaje' => $mensaje,
            'tipo' => $tipo
        ];
    }

    private function redirectToLogin()
    {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}
